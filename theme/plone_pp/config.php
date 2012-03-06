<?php

$THEME->sheets = array('user_styles','plone','geshi');
$THEME->standardsheets = array('styles_layout');
$THEME->parent = 'custom_corners';  // put the name of the theme folder you want to use as parent here.
$THEME->parentsheets = array('user_styles');


$THEME->customcorners = true;


$THEME->custompix = true;


$THEME->modsheets = true;

/// When this is enabled, then this theme will search for
/// files named "styles.php" inside all Activity modules and
/// include them.   This allows modules to provide some basic
/// layouts so they work out of the box.
/// It is HIGHLY recommended to leave this enabled.


$THEME->blocksheets = true;

/// When this is enabled, then this theme will search for
/// files named "styles.php" inside all Block modules and
/// include them.   This allows Blocks to provide some basic
/// layouts so they work out of the box.
/// It is HIGHLY recommended to leave this enabled.


$CFG->portail="http://cipcnet.insa-lyon.fr";

$THEME->rarrow="&rarr;";
$THEME->larrow="&larr;";

//$THEME->layouttable = array('left', 'right', 'middle');


?>
