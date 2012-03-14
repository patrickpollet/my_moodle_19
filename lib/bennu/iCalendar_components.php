<?php // $Id$

/**
 *  BENNU - PHP iCalendar library
 *  (c) 2005-2006 Ioannis Papaioannou (pj@moodle.org). All rights reserved.
 *
 *  Released under the LGPL.
 *
 *  See http://bennu.sourceforge.net/ for more information and downloads.
 *
 * @author Ioannis Papaioannou 
 * @version $Id$
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 */

class iCalendar_component {
    var $name             = NULL;
    var $properties       = NULL;
    var $components       = NULL;
    var $valid_properties = NULL;
    var $valid_components = NULL;
    /**
     * Added to hold errors from last run of unserialize
     * @var $parser_errors array
     */
    var $parser_errors = NULL;

    function iCalendar_component() {
        $this->construct();
    }

    function construct() {
        // Initialize the components array
        if(empty($this->components)) {
            $this->components = array();
            foreach($this->valid_components as $name) {
                $this->components[$name] = array();
            }
        }
    }

    function get_name() {
        return $this->name;
    }

    function add_property($name, $value = NULL, $parameters = NULL) {

        // Uppercase first of all
        $name = strtoupper($name);

        // Are we trying to add a valid property?
        $xname = false;
        if(!isset($this->valid_properties[$name])) {
            // If not, is it an x-name as per RFC 2445?
            if(!rfc2445_is_xname($name)) {
                return false;
            }
            // Since this is an xname, all components are supposed to allow this property
            $xname = true;
        }

        // Create a property object of the correct class
        if($xname) {
            $property = new iCalendar_property_x;
            $property->set_name($name);
        }
        else {
            $classname = 'iCalendar_property_'.strtolower(str_replace('-', '_', $name));
            $property = new $classname;
        }

        // If $value is NULL, then this property must define a default value.
        if($value === NULL) {
            $value = $property->default_value();
            if($value === NULL) {
                return false;
            }
        }

        // Set this property's parent component to ourselves, because some
        // properties behave differently according to what component they apply to.
        $property->set_parent_component($this->name);

        // Set parameters before value; this helps with some properties which
        // accept a VALUE parameter, and thus change their default value type.

        // The parameters must be valid according to property specifications
        if(!empty($parameters)) {
            foreach($parameters as $paramname => $paramvalue) {
                if(!$property->set_parameter($paramname, $paramvalue)) {
                    return false;
                }
            }

            // Some parameters interact among themselves (e.g. ENCODING and VALUE)
            // so make sure that after the dust settles, these invariants hold true
            if(!$property->invariant_holds()) {
                return false;
            }
        }

        // $value MUST be valid according to the property data type
        if(!$property->set_value($value)) {
            return false;
        }

        // If this property is restricted to only once, blindly overwrite value
        if(!$xname && $this->valid_properties[$name] & RFC2445_ONCE) {
            $this->properties[$name] = array($property);
        }

        // Otherwise add it to the instance array for this property
        else {
            $this->properties[$name][] = $property;
        }

        // Finally: after all these, does the component invariant hold?
        if(!$this->invariant_holds()) {
            // If not, completely undo the property addition
            array_pop($this->properties[$name]);
            if(empty($this->properties[$name])) {
                unset($this->properties[$name]);
            }
            return false;
        }

        return true;        
        
    }

    function add_component($component) {

        // With the detailed interface, you can add only components with this function
        if(!is_object($component) || !is_subclass_of($component, 'iCalendar_component')) {
            return false;
        }

        $name = $component->get_name();

        // Only valid components as specified by this component are allowed
        if(!in_array($name, $this->valid_components)) {
            return false;
        }

        // Add it
        $this->components[$name][] = $component;

        return true;
    }

    function get_property_list($name) {
    }

    function invariant_holds() {
        return true;
    }

    function is_valid() {
        // If we have any child components, check that they are all valid
        if(!empty($this->components)) {
            foreach($this->components as $component => $instances) {
                foreach($instances as $number => $instance) {
                    if(!$instance->is_valid()) {
                        return false;
                    }
                }
            }
        }

        // Finally, check the valid property list for any mandatory properties
        // that have not been set and do not have a default value
        foreach($this->valid_properties as $property => $propdata) {
            if(($propdata & RFC2445_REQUIRED) && empty($this->properties[$property])) {
                $classname = 'iCalendar_property_'.strtolower(str_replace('-', '_', $property));
                $object    = new $classname;
                if($object->default_value() === NULL) {
                    return false;
                }
                unset($object);
            }
        }

        return true;
    }
    
    function serialize() {
        // Check for validity of the object
        if(!$this->is_valid()) {
            return false;
        }

        // Maybe the object is valid, but there are some required properties that
        // have not been given explicit values. In that case, set them to defaults.
        foreach($this->valid_properties as $property => $propdata) {
            if(($propdata & RFC2445_REQUIRED) && empty($this->properties[$property])) {
                $this->add_property($property);
            }
        }

        // Start tag
        $string = rfc2445_fold('BEGIN:'.$this->name) . RFC2445_CRLF;

        // List of properties
        if(!empty($this->properties)) {
            foreach($this->properties as $name => $properties) {
                foreach($properties as $property) {
                    $string .= $property->serialize();
                }
            }
        }

        // List of components
        if(!empty($this->components)) {
            foreach($this->components as $name => $components) {
                foreach($components as $component) {
                    $string .= $component->serialize();
                }
            }
        }

        // End tag
        $string .= rfc2445_fold('END:'.$this->name) . RFC2445_CRLF;

        return $string;
    }
    
    /**
    * unserialize()
    *
    * I needed a way to convert an iCalendar component back to a Bennu object so I could
    * easily access and modify it after it had been stored; if this functionality is already
    * present somewhere in the library, I apologize for adding it here unnecessarily; however,
    * I couldn't find it so I added it myself.
    * @param string $string the iCalendar object to load in to this iCalendar_component
    * @return bool true if the file parsed with no errors. False if there were errors.
    */
    
    function unserialize($string) {
                
        // Check to see if this really is the type of iCalendar component that has been instantiated.
        
        $lines = explode(RFC2445_CRLF, $string);
        $components = array(); // Initialise a stack of components        
        $linecount = 0; // Stores the line of the file we're on, for error reporting
        
        $rightcomponent = ereg('BEGIN:'.$this->name, $lines[0], $regs);
        
        if ($rightcomponent === false) {
            $this->parser_error($count, 1);
        }
        
        // The component type is correct; now, search for properties and add them.
        array_shift($lines);
        array_pop($lines);
        array_pop($lines);
        foreach ($lines as $line) {
            $linecount++;
            // Loop through each line in the middle area of the component;
            // if it's a property (according to the regex), attempt to add it
            // as a property; if it's an invalid property, throw an exception.
            // If it's the beginning of another component, set a flag indicating
            // that it should be collected as a component and unserialized itself
            // once the whole component string has been collected.
            if (ereg('BEGIN:(VEVENT|VTODO|VJOURNAL|VFREEBUSY|VTIMEZONE|VALARM|DAYLIGHT|STANDARD)', $line, $regs)) {
                // It's a component.
                if(strpos($regs[1], 'V') === 0) {
                	$regs[1] = substr($regs[1], 1);
                }
                $cname = 'iCalendar_' . strtolower($regs[1]);                              
                $componentstr = $line . RFC2445_CRLF;
                $component = new stdClass;
                $component->object = new $cname;
                $component->string = $componentstr;
                $compenent->linestart = $linecount;
                array_push($components, $component); // Push a new component onto the stack
                unset($component);
                unset($componentstr);
            } else if (strpos($line, 'END:') === 0) {                
                // It's the END of a component.
                $component = array_pop($components); // Pop the top component off the stack - we're now done with it
                $component->string .= $line . RFC2445_CRLF;
                $result = $component->object->unserialize($component->string);              
                if ($result === false) {
                    $this->parser_error($linecount, 2);
                }
                $parent_component = array_pop($components); // Pop the component's conatining component off the stack so we can add this component to it.
                if($parent_component == null) {
                    $parent_component = new stdClass;
                	$parent_component->object = $this; // If there's no components on the stack, use the iCalendar object
                }                
                if ($parent_component->object->add_component($component->object) === false) {
                    $this->parser_error($linecount, 3);
                }
                if ($parent_component->object != $this) { // If we're not using the iCalendar
                	array_push($components, $parent_component); // Put the component back on the stack
                }
                unset($component);
            } else {
                if (ereg('([A-Z-]+)((;[A-Z-]+=[^:]+)+)?:(.*)', $line, $regs)) {
                    $component = array_pop($components); // Get the component off the stack so we can add properties to it
                    if ($component == null) { // If there's nothing on the stack
                    	$component->object = $this; // use the iCalendar
                    }
                    // It is in fact a property. The above regex should place the
                    // property name in $regs[1], the param string in $regs[3], and
                    // the value in $regs[4].
                    if (isset($component->string)) {
                        // Since we are currently inside a sub-component, just add
                        // this property to the string...it will get unserialized when
                        // that component is unserialized.
                        $component->string .= $line . RFC2445_CRLF;
                    } else {
                        $params = null;
                        if (trim($regs[3]) <> '') {
                            // There are parameters...set up a parameters array
                            $params = array();
                            foreach (explode(';', $regs[3]) as $param) {
                                $split = explode('=', $param);
                                if (count($split) == 2) {
                                    $params[$split[0]] = $split[1];
                                } else {
                                    // couldn't be parsed into a valid parameter; just ignore it
                                }
                            }
                        }
                        $pname = strtolower($regs[1]);
                        if ($component->object->add_property($pname, $regs[4], $params) === false) {
                            $this->parser_error($linecount, 4);
                        }
                    }
                    if($component->object != $this) { // If we're not using the iCalendar
                        array_push($components, $component); // Put the component back on the stack	
                    }                    
                    unset($component);
                
                } else {
                    $this->parser_error($linecount, 5);
                }
            }
        }
        
        if(count($this->parser_errors) > 0) {
        	return false;
        } else {
        	return true;
        }
        
    }
    
    function clear_errors() {
    	$this->parser_errors = array();
    }
    
    /**
     * Record an error
     * 
     * Records an error (line number and error code) to allow errors to be reporting without having to stop parser.
     * Valid error codes are:
     * 1: Invalid Component Type
     * 2: Failure unserialising sub-component
     * 3: Failure adding sub-component
     * 4: Failure adding property
     * 5: Invalid line
     * 
     * @param $line int
     * @param $code int
     */
    function parser_error($line, $code) {        
        if($code > 0 && $code <= 5) {
        	$error = new stdClass;
            $error->line = $line;
            $error->code = $code;
            $this->parser_errors[] = $error;
            return true;
        } else {
            return false;	
        }        
    }
    // END MATERIAL ADDED TO ORIGINAL SOURCE CODE //

}

class iCalendar extends iCalendar_component {
    var $name = 'VCALENDAR';

    function construct() {
        $this->valid_properties = array(
            'CALSCALE'    => RFC2445_OPTIONAL | RFC2445_ONCE,
            'METHOD'      => RFC2445_OPTIONAL | RFC2445_ONCE,
            'PRODID'      => RFC2445_REQUIRED | RFC2445_ONCE,
            'VERSION'     => RFC2445_REQUIRED | RFC2445_ONCE,
            RFC2445_XNAME => RFC2445_OPTIONAL 
        );

        $this->valid_components = array(
            'VEVENT', 'VTODO', 'VJOURNAL', 'VFREEBUSY', 'VTIMEZONE', 'VALARM'
        );
        parent::construct();
    }

}

class iCalendar_event extends iCalendar_component {

    var $name       = 'VEVENT';
    var $properties;
    
    function construct() {
        
        $this->valid_components = array('VALARM');

        $this->valid_properties = array(
            'CLASS'          => RFC2445_OPTIONAL | RFC2445_ONCE,
            'CREATED'        => RFC2445_OPTIONAL | RFC2445_ONCE,
            'DESCRIPTION'    => RFC2445_OPTIONAL | RFC2445_ONCE,
            // Standard ambiguous here: in 4.6.1 it says that DTSTAMP in optional,
            // while in 4.8.7.2 it says it's REQUIRED. Go with REQUIRED.
            'DTSTAMP'        => RFC2445_REQUIRED | RFC2445_ONCE,
            // Standard ambiguous here: in 4.6.1 it says that DTSTART in optional,
            // while in 4.8.2.4 it says it's REQUIRED. Go with REQUIRED.
            'DTSTART'        => RFC2445_REQUIRED | RFC2445_ONCE,
            'GEO'            => RFC2445_OPTIONAL | RFC2445_ONCE,
            'LAST-MODIFIED'  => RFC2445_OPTIONAL | RFC2445_ONCE,
            'LOCATION'       => RFC2445_OPTIONAL | RFC2445_ONCE,
            'ORGANIZER'      => RFC2445_OPTIONAL | RFC2445_ONCE,
            'PRIORITY'       => RFC2445_OPTIONAL | RFC2445_ONCE,
            'SEQUENCE'       => RFC2445_OPTIONAL | RFC2445_ONCE,
            'STATUS'         => RFC2445_OPTIONAL | RFC2445_ONCE,
            'SUMMARY'        => RFC2445_OPTIONAL | RFC2445_ONCE,
            'TRANSP'         => RFC2445_OPTIONAL | RFC2445_ONCE,
            // Standard ambiguous here: in 4.6.1 it says that UID in optional,
            // while in 4.8.4.7 it says it's REQUIRED. Go with REQUIRED.
            'UID'            => RFC2445_REQUIRED | RFC2445_ONCE,
            'URL'            => RFC2445_OPTIONAL | RFC2445_ONCE,
            'RECURRENCE-ID'  => RFC2445_OPTIONAL | RFC2445_ONCE,
            'DTEND'          => RFC2445_OPTIONAL | RFC2445_ONCE,
            'DURATION'       => RFC2445_OPTIONAL | RFC2445_ONCE,
            'ATTACH'         => RFC2445_OPTIONAL,
            'ATTENDEE'       => RFC2445_OPTIONAL,
            'CATEGORIES'     => RFC2445_OPTIONAL,
            'COMMENT'        => RFC2445_OPTIONAL,
            'CONTACT'        => RFC2445_OPTIONAL,
            'EXDATE'         => RFC2445_OPTIONAL,
            'EXRULE'         => RFC2445_OPTIONAL,
            'REQUEST-STATUS' => RFC2445_OPTIONAL,
            'RELATED-TO'     => RFC2445_OPTIONAL,
            'RESOURCES'      => RFC2445_OPTIONAL,
            'RDATE'          => RFC2445_OPTIONAL,
            'RRULE'          => RFC2445_OPTIONAL,
            RFC2445_XNAME    => RFC2445_OPTIONAL
        );

        parent::construct();
    }

    function invariant_holds() {
        // DTEND and DURATION must not appear together
        if(isset($this->properties['DTEND']) && isset($this->properties['DURATION'])) {
            return false;
        }

        
        if(isset($this->properties['DTEND']) && isset($this->properties['DTSTART'])) {
            // DTEND must be later than DTSTART
            // The standard is not clear on how to hande different value types though
            // TODO: handle this correctly even if the value types are different
            if($this->properties['DTEND'][0]->value <= $this->properties['DTSTART'][0]->value) {
                return false;
            }

            // DTEND and DTSTART must have the same value type
            if($this->properties['DTEND'][0]->val_type != $this->properties['DTSTART'][0]->val_type) {
                return false;
            }

        }
        return true;
    }

}

class iCalendar_todo extends iCalendar_component {
    var $name       = 'VTODO';
    var $properties;

    function construct() {
        
        $this->valid_components = array('VALARM');

        $this->valid_properties = array(
            'CLASS'       => RFC2445_OPTIONAL | RFC2445_ONCE,
            'COMPLETED'   => RFC2445_OPTIONAL | RFC2445_ONCE,
            'CREATED'     => RFC2445_OPTIONAL | RFC2445_ONCE,
            'DESCRIPTION' => RFC2445_OPTIONAL | RFC2445_ONCE,
            'DTSTAMP'     => RFC2445_OPTIONAL | RFC2445_ONCE,
            'DTSTAP'     => RFC2445_OPTIONAL | RFC2445_ONCE,
            'GEO'         => RFC2445_OPTIONAL | RFC2445_ONCE,
            'LAST-MODIFIED'    => RFC2445_OPTIONAL | RFC2445_ONCE,
            'LOCATION'    => RFC2445_OPTIONAL | RFC2445_ONCE,
            'ORGANIZER'   => RFC2445_OPTIONAL | RFC2445_ONCE,
            'PERCENT'     => RFC2445_OPTIONAL | RFC2445_ONCE,
            'PRIORITY'    => RFC2445_OPTIONAL | RFC2445_ONCE,
            'RECURID'     => RFC2445_OPTIONAL | RFC2445_ONCE,
            'SEQUENCE'    => RFC2445_OPTIONAL | RFC2445_ONCE,
            'STATUS'      => RFC2445_OPTIONAL | RFC2445_ONCE,
            'SUMMARY'     => RFC2445_OPTIONAL | RFC2445_ONCE,
            'UID'         => RFC2445_OPTIONAL | RFC2445_ONCE,
            'URL'         => RFC2445_OPTIONAL | RFC2445_ONCE,
            'DUE'         => RFC2445_OPTIONAL | RFC2445_ONCE,
            'DURATION'    => RFC2445_OPTIONAL | RFC2445_ONCE,
            'ATTACH'      => RFC2445_OPTIONAL,
            'ATTENDEE'    => RFC2445_OPTIONAL,
            'CATEGORIES'  => RFC2445_OPTIONAL,
            'COMMENT'     => RFC2445_OPTIONAL,
            'CONTACT'     => RFC2445_OPTIONAL,
            'EXDATE'      => RFC2445_OPTIONAL,
            'EXRULE'      => RFC2445_OPTIONAL,
            'RSTATUS'     => RFC2445_OPTIONAL,
            'RELATED'     => RFC2445_OPTIONAL,
            'RESOURCES'   => RFC2445_OPTIONAL,
            'RDATE'       => RFC2445_OPTIONAL,
            'RRULE'       => RFC2445_OPTIONAL,
            RFC2445_XNAME => RFC2445_OPTIONAL
        );

        parent::construct();
    }
    
    function invariant_holds() {
        // DTEND and DURATION must not appear together
        if(isset($this->properties['DTEND']) && isset($this->properties['DURATION'])) {
            return false;
        }

        
        if(isset($this->properties['DTEND']) && isset($this->properties['DTSTART'])) {
            // DTEND must be later than DTSTART
            // The standard is not clear on how to hande different value types though
            // TODO: handle this correctly even if the value types are different
            if($this->properties['DTEND'][0]->value <= $this->properties['DTSTART'][0]->value) {
                return false;
            }

            // DTEND and DTSTART must have the same value type
            if($this->properties['DTEND'][0]->val_type != $this->properties['DTSTART'][0]->val_type) {
                return false;
            }

        }
        
        if(isset($this->properties['DUE']) && isset($this->properties['DTSTART'])) {
            if($this->properties['DUE'][0]->value <= $this->properties['DTSTART'][0]->value) {
                return false;
            }   
        }
        
        return true;
    }
    
}

class iCalendar_journal extends iCalendar_component {
    var $name = 'VJOURNAL';
    var $properties;
    
    function construct() {
    	
        $this->valid_properties = array(
            'CLASS'     =>  RFC2445_OPTIONAL | RFC2445_ONCE,
            'CREATED'   =>  RFC2445_OPTIONAL | RFC2445_ONCE,
            'DESCRIPTION'   =>  RFC2445_OPTIONAL | RFC2445_ONCE,
            'DTSTART'   =>  RFC2445_OPTIONAL | RFC2445_ONCE,
            'DTSTAMP'   =>  RFC2445_OPTIONAL | RFC2445_ONCE,
            'LAST-MODIFIED' =>  RFC2445_OPTIONAL | RFC2445_ONCE,
            'ORGANIZER' =>  RFC2445_OPTIONAL | RFC2445_ONCE,
            'RECURRANCE-ID' =>  RFC2445_OPTIONAL | RFC2445_ONCE,
            'SEQUENCE'  =>  RFC2445_OPTIONAL | RFC2445_ONCE,
            'STATUS'    =>  RFC2445_OPTIONAL | RFC2445_ONCE,
            'SUMMARY'   =>  RFC2445_OPTIONAL | RFC2445_ONCE,
            'UID'       =>  RFC2445_OPTIONAL | RFC2445_ONCE,
            'URL'       =>  RFC2445_OPTIONAL | RFC2445_ONCE,
            'ATTACH'    =>  RFC2445_OPTIONAL,
            'ATTENDEE'  =>  RFC2445_OPTIONAL,
            'CATEGORIES'    =>  RFC2445_OPTIONAL,
            'COMMENT'   => RFC2445_OPTIONAL,
            'CONTACT'   => RFC2445_OPTIONAL,
            'EXDATE'    => RFC2445_OPTIONAL,
            'EXRULE'    => RFC2445_OPTIONAL,
            'RELATED-TO'    => RFC2445_OPTIONAL,
            'RDATE'          => RFC2445_OPTIONAL,
            'RRULE'          => RFC2445_OPTIONAL,
            RFC2445_XNAME    => RFC2445_OPTIONAL            
        );
        
         parent::construct();
        
    }
}

class iCalendar_freebusy extends iCalendar_component {
    var $name       = 'VFREEBUSY';
    var $properties;

    function construct() {
        $this->valid_components = array();
        $this->valid_properties = array(
            'CONTACT'        => RFC2445_OPTIONAL | RFC2445_ONCE,
            'DTSTART'    => RFC2445_OPTIONAL | RFC2445_ONCE,
            'DTEND'       => RFC2445_OPTIONAL | RFC2445_ONCE,
            'DURATION'       => RFC2445_OPTIONAL | RFC2445_ONCE,
            'DTSTAMP'       => RFC2445_OPTIONAL | RFC2445_ONCE,
            'ORGANIZER'       => RFC2445_OPTIONAL | RFC2445_ONCE,
            'UID'       => RFC2445_OPTIONAL | RFC2445_ONCE,
            'URL'       => RFC2445_OPTIONAL | RFC2445_ONCE,
            // TODO: the next two are components of their own!
            'ATTENDEE'  => RFC2445_OPTIONAL,
            'COMMENT'  => RFC2445_OPTIONAL,
            'FREEBUSY'  => RFC2445_OPTIONAL,
            'RSTATUS'  => RFC2445_OPTIONAL,
            RFC2445_XNAME => RFC2445_OPTIONAL
        );
        
        parent::construct();
    }
    
    function invariant_holds() {
        // DTEND and DURATION must not appear together
        if(isset($this->properties['DTEND']) && isset($this->properties['DURATION'])) {
            return false;
        }

        
        if(isset($this->properties['DTEND']) && isset($this->properties['DTSTART'])) {
            // DTEND must be later than DTSTART
            // The standard is not clear on how to hande different value types though
            // TODO: handle this correctly even if the value types are different
            if($this->properties['DTEND'][0]->value <= $this->properties['DTSTART'][0]->value) {
                return false;
            }

            // DTEND and DTSTART must have the same value type
            if($this->properties['DTEND'][0]->val_type != $this->properties['DTSTART'][0]->val_type) {
                return false;
            }

        }
        return true;
    }
}

class iCalendar_alarm extends iCalendar_component {
    var $name       = 'VALARM';
    var $properties;

    function construct() {
        $this->valid_components = array();
        $this->valid_properties = array(
            'ACTION'    => RFC2445_REQUIRED | RFC2445_ONCE,
            'TRIGGER'   => RFC2445_REQUIRED | RFC2445_ONCE,
            // If one of these 2 occurs, so must the other.
            'DURATION'  => RFC2445_OPTIONAL | RFC2445_ONCE,
            'REPEAT'  => RFC2445_OPTIONAL | RFC2445_ONCE, 
            // The following is required if action == "PROCEDURE" | "AUDIO"           
            'ATTACH'    => RFC2445_OPTIONAL,
            // The following is required if trigger == "EMAIL" | "DISPLAY" 
            'DESCRIPTION'  => RFC2445_OPTIONAL | RFC2445_ONCE,
            // The following are required if action == "EMAIL"
            'SUMMARY'   => RFC2445_OPTIONAL | RFC2445_ONCE,
            'ATTENDEE'  => RFC2445_OPTIONAL,
            RFC2445_XNAME   => RFC2445_OPTIONAL
        );
     
        parent::construct();
    }
        
    function invariant_holds() {
        // DTEND and DURATION must not appear together
        if(isset($this->properties['ACTION'])) {
            switch ($this->properties['ACTION'][0]->value) {
            	case 'AUDIO':
                    if (!isset($this->properties['ATTACH'])) {
                    	return false;
                    }
                    break;
                case 'DISPLAY':
                    if (!isset($this->properties['DESCRIPTION'])) {
                    	return false;
                    }
                    break;
                case 'EMAIL':
                    if (!isset($this->properties['DESCRIPTION']) || !isset($this->properties['SUMMARY']) || !isset($this->properties['ATTACH'])) {
                        return false;
                    }
                    break;
                case 'PROCEDURE':
                    if (!isset($this->properties['ATTACH']) || count($this->properties['ATTACH']) > 1) {
                    	return false;
                    }
                    break;
            }
        }
        return true;
    }
        
        
}

class iCalendar_timezone extends iCalendar_component {
    var $name       = 'VTIMEZONE';
    var $properties;

    function construct() {

        $this->valid_components = array('STANDARD', 'DAYLIGHT');

        $this->valid_properties = array(
            'TZID'        => RFC2445_REQUIRED | RFC2445_ONCE,
            'LAST-MODIFIED'    => RFC2445_OPTIONAL | RFC2445_ONCE,
            'TZURL'       => RFC2445_OPTIONAL | RFC2445_ONCE,
            // TODO: the next two are components of their own!
            //'STANDARDC'   => RFC2445_REQUIRED,
            //'DAYLIGHTC'   => RFC2445_REQUIRED,
            RFC2445_XNAME => RFC2445_OPTIONAL
        );
        
        parent::construct();
    }

}

class iCalendar_standard extends iCalendar_component {
    var $name       = 'STANDARD';
    var $properties;
    
    function construct() {
        $this->valid_components = array();
        $this->valid_properties = array(
            'DTSTART'   =>  RFC2445_REQUIRED | RFC2445_ONCE,
            'TZOFFSETTO'    =>  RFC2445_REQUIRED | RFC2445_ONCE,
            'TZOFFSETFROM'  =>  RFC2445_REQUIRED | RFC2445_ONCE,
            'COMMENT'   =>  RFC2445_OPTIONAL,
            'RDATE'   =>  RFC2445_OPTIONAL,
            'RRULE'   =>  RFC2445_OPTIONAL,
            'TZNAME'   =>  RFC2445_OPTIONAL,
            RFC2445_XNAME   =>  RFC2445_OPTIONAL,
        ); 
        parent::construct();  
    }
}

class iCalendar_daylight extends iCalendar_standard {
    var $name   =   'DAYLIGHT';
}

// REMINDER: DTEND must be later than DTSTART for all components which support both
// REMINDER: DUE must be later than DTSTART for all components which support both

?>