<?php
/*
*************************************************************************
    MODx Content Management System and PHP Application Framework
    Managed and maintained by Raymond Irving, Ryan Thrash and the
    MODx community
*************************************************************************
    MODx is an opensource PHP/MySQL content management system and content
    management framework that is flexible, adaptable, supports XHTML/CSS
    layouts, and works with most web browsers, including Safari.

    MODx is distributed under the GNU General Public License
*************************************************************************

    MODx CMS and Application Framework ("MODx")
    Copyright 2005 and forever thereafter by Raymond Irving & Ryan Thrash.
    All rights reserved.

    This file and all related or dependant files distributed with this filie
    are considered as a whole to make up MODx.

    MODx is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    MODx is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with MODx (located in "/assets/docs/"); if not, write to the Free Software
    Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA

    For more information on MODx please visit http://modxcms.com/

**************************************************************************
    Originally based on Etomite by Alex Butter
**************************************************************************
*/


/**
 *  Filename: manager/index.php
 *  Function: This file is the main root file for MODx. It is
 *          only file that will be directly requested, and
 *          depending on the request, will branch different
 *          content
 */

// get start time
$mtime = microtime(); $mtime = explode(" ",$mtime); $mtime = $mtime[1] + $mtime[0]; $tstart = $mtime;

define("IN_MANAGER_MODE", "true");  // we use this to make sure files are accessed through
                                    // the manager instead of seperately.

// include version info
require_once ('./includes/version.inc.php');

// harden it
require_once('./includes/protect.inc.php');

// send anti caching headers
header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
header("Cache-Control: no-store, no-cache, must-revalidate");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

error_reporting(E_ALL & ~E_STRICT & ~E_NOTICE);

// set some runtime options
$incPath = str_replace("\\","/",dirname(__FILE__)."/includes/"); // Mod by Raymond
set_include_path(get_include_path() . PATH_SEPARATOR . $incPath);

// Suppress magic quotes
// WARNING: This writes to the GPC arrays. Third party scripts may try to remove slashes a second time on servers with magic quotes 'on'.
if (version_compare(phpversion(), "5.4") < 0) {
    @set_magic_quotes_runtime(0);

    // include_once the magic_quotes_gpc workaround
    include_once "quotes_stripper.inc.php";
}

if (!defined("ENT_COMPAT")) define("ENT_COMPAT", 2);
if (!defined("ENT_NOQUOTES")) define("ENT_NOQUOTES", 0);
if (!defined("ENT_QUOTES")) define("ENT_QUOTES", 3);

// set the document_root :|
if(!isset($_SERVER["DOCUMENT_ROOT"]) || empty($_SERVER["DOCUMENT_ROOT"])) {
    $_SERVER["DOCUMENT_ROOT"] = str_replace($_SERVER["PATH_INFO"], "", preg_replace("/\\\\/", "/", $_SERVER["PATH_TRANSLATED"]))."/";
}

define("IN_ETOMITE_SYSTEM", "true"); // for backward compatibility with 0.6

// include_once config file
$config_filename = "./includes/config.inc.php";
if (!file_exists($config_filename)) {
    echo '<h3>Unable to load configuration settings</h3>';
    echo 'Please run the '.CMS_NAME.' <a href="../install">install utility</a>';
    exit;
}

// include the database configuration file
include_once "config.inc.php";

// initiate the content manager class
include_once "document.parser.class.inc.php";
$modx = new DocumentParser;
$modx->loadExtension("ManagerAPI");
$modx->getSettings();
$etomite = &$modx; // for backward compatibility

// Sanitise UTF-8 GPC (needs to be done after $modx->getSettings)
require('utf8.sanitise.php');

// connect to the database
$modx->db->connect();

// start session
startCMSSession();

// invoke OnManagerSessionInit event
$modx->invokeEvent("OnManagerSessionInit", array());

// get the settings from the database
require('settings.inc.php');

// get the manager $_lang array
require_once('get_manager_language.inc.php');

// send the charset header
header('Content-Type: text/html; charset='.$modx_manager_charset);

// accesscontrol.php checks to see if the user is logged in. If not, a log in form is shown
include_once "accesscontrol.inc.php";

// double check the session
if(!isset($_SESSION['mgrValidated'])){
    echo "Not Logged In!";
    exit;
}

// include_once the style variables file
if(isset($manager_theme) && !isset($_style)) {
    $_style = array();
    include_once "media/style/".$manager_theme."/style.php";
}

// check if user is allowed to access manager interface
if(isset($allow_manager_access) && $allow_manager_access==0) {
    include_once "manager.lockout.inc.php";
}

// include_once the error handler
include_once "error.class.inc.php";
$e = new errorHandler;

// Initialize System Alert Message Queque
if (!isset($_SESSION['SystemAlertMsgQueque'])) $_SESSION['SystemAlertMsgQueque'] = array();
$SystemAlertMsgQueque = &$_SESSION['SystemAlertMsgQueque'];

// first we check to see if this is a frameset request
if (!isset($_POST['a']) && !isset($_GET['a']) && ($e->getError()==0)) {
    // this looks to be a top-level frameset request, so let's serve up a frameset
    include_once "frames/1.php";
    exit;
}

// OK, let's retrieve the action directive from the request
if(isset($_GET['a']) && isset($_POST['a'])) {
    $e->setError(100);
    $e->dumpError();
    // set $e to a corresponding errorcode
    // we know that if an error occurs here, something's wrong,
    // so we dump the error, thereby stopping the script.

} elseif($_REQUEST['a'] != '1' && $_REQUEST['a'] != '30' && $modx->hasPermission('settings') && (!isset($settings_version) || $settings_version != CMS_RELEASE_VERSION)) {
	// seems to be a new install - send the user to the configuration page
	$action = 17;
} else {
	// Normal action
    $action= (int)$_REQUEST['a'];
}

// save page to manager object
$modx->manager->action = $action;

// attempt to foil some simple types of CSRF attacks
if (isset($modx->config['validate_referer']) && intval($modx->config['validate_referer'])) {
    if (isset($_SERVER['HTTP_REFERER'])) {
        $referer = $_SERVER['HTTP_REFERER'];

        if (!empty($referer)) {
            if (!preg_match('/^'.preg_quote(MODX_SITE_URL, '/').'/i', $referer)) {
                echo 'A possible CSRF attempt was detected from referer: '.strip_tags($referer);
                exit();
            }
        } else {
            echo "A possible CSRF attempt was detected. No referer was provided by the client.";
            exit();
        }
    } else {
        echo "A possible CSRF attempt was detected. No referer was provided by the server.";
        exit();
    }
}

// invoke OnManagerPageInit event
$modx->invokeEvent("OnManagerPageInit", array("action" => $action));


// Now we decide what to do according to the action request. This is a BIG list :)
switch ($action) {
/********************************************************************/
/* frame management - show the requested frame                      */
/********************************************************************/
    case 1 :
        // get the requested frame
        $frame = preg_replace('/[^a-z0-9]/i','',$_REQUEST['f']);
        if($frame>9) {
            $enable_debug=false;    // this is to stop the debug thingy being attached to the framesets
        }
        include_once "frames/".$frame.".php";
    break;
/********************************************************************/
/* show the homepage                                                */
/********************************************************************/
    case 2:
        // get the home page
        require_once('header.inc.php');
        include_once "actions/welcome.static.php";
        include_once "footer.inc.php";
    break;
/********************************************************************/
/* document data                                                    */
/********************************************************************/
    case 3:
        // get the page to show document's data
        require_once('header.inc.php');
        include_once "actions/document_data.static.php";
        include_once "footer.inc.php";
    break;
/********************************************************************/
/* content management                                               */
/********************************************************************/
    case 85:
        // get the mutate page for adding a folder
        require_once('header.inc.php');
        include_once "actions/mutate_content.dynamic.php";
        include_once "footer.inc.php";
    break;
    case 27:
        // get the mutate page for changing content
        require_once('header.inc.php');
        include_once "actions/mutate_content.dynamic.php";
        include_once "footer.inc.php";
    break;
    case 4:
        // get the mutate page for adding content
        require_once('header.inc.php');
        include_once "actions/mutate_content.dynamic.php";
        include_once "footer.inc.php";
    break;
    case 5:
        // get the save processor
        include_once "processors/save_content.processor.php";
    break;
    case 6:
        // get the delete processor
        include_once "processors/delete_content.processor.php";
    break;
    case 63:
        // get the undelete processor
        include_once "processors/undelete_content.processor.php";
    break;
    case 51:
        // get the move action
        require_once('header.inc.php');
        include_once "actions/move_document.dynamic.php";
        include_once "footer.inc.php";
    break;
    case 52:
        // get the move document processor
        include_once "processors/move_document.processor.php";
    break;
    case 61:
        // get the processor for publishing content
        include_once "processors/publish_content.processor.php";
    break;
    case 62:
        // get the processor for publishing content
        include_once "processors/unpublish_content.processor.php";
    break;
/********************************************************************/
/* show the wait page - gives the tree time to refresh (hopefully)  */
/********************************************************************/
    case 7:
        // get the wait page (so the tree can reload)
        require_once('header.inc.php');
        include_once "actions/wait.static.php";
        include_once "footer.inc.php";
    break;
/********************************************************************/
/* let the user log out                                             */
/********************************************************************/
    case 8:
        // get the logout processor
        include_once "processors/logout.processor.php";
    break;
/********************************************************************/
/* user management                                                  */
/********************************************************************/
    case 87:
        // get the new web user page
        require_once('header.inc.php');
        include_once "actions/mutate_web_user.dynamic.php";
        include_once "footer.inc.php";
    break;
    case 88:
        // get the edit web user page
        require_once('header.inc.php');
        include_once "actions/mutate_web_user.dynamic.php";
        include_once "footer.inc.php";
    break;
    case 89:
        // get the save web user processor
        include_once "processors/save_web_user.processor.php";
    break;
    case 90:
        // get the delete web user page
        include_once "processors/delete_web_user.processor.php";
    break;
    case 11:
        // get the new user page
        require_once('header.inc.php');
        include_once "actions/mutate_user.dynamic.php";
        include_once "footer.inc.php";
    break;
    case 12:
        // get the edit user page
        require_once('header.inc.php');
        include_once "actions/mutate_user.dynamic.php";
        include_once "footer.inc.php";
    break;
    case 32:
        // get the save user processor
        include_once "processors/save_user.processor.php";
    break;
    case 28:
        // get the change password page
        require_once('header.inc.php');
        include_once "actions/mutate_password.dynamic.php";
        include_once "footer.inc.php";
    break;
    case 34:
        // get the save new password page
        include_once "processors/save_password.processor.php";
    break;
    case 33:
        // get the delete user page
        include_once "processors/delete_user.processor.php";
    break;
/********************************************************************/
/* role management                                                  */
/********************************************************************/
    case 38:
        // get the new role page
        require_once('header.inc.php');
        include_once "actions/mutate_role.dynamic.php";
        include_once "footer.inc.php";
    break;
    case 35:
        // get the edit role page
        require_once('header.inc.php');
        include_once "actions/mutate_role.dynamic.php";
        include_once "footer.inc.php";
    break;
    case 36:
        // get the save role page
        include_once "processors/save_role.processor.php";
    break;
    case 37:
        // get the delete role page
        include_once "processors/delete_role.processor.php";
    break;
/********************************************************************/
/* template management                                              */
/********************************************************************/
    case 16:
        // get the edit template action
        require_once('header.inc.php');
        include_once "actions/mutate_templates.dynamic.php";
        include_once "footer.inc.php";
    break;
    case 19:
        // get the new template action
        require_once('header.inc.php');
        include_once "actions/mutate_templates.dynamic.php";
        include_once "footer.inc.php";
    break;
    case 20:
        // get the save processor
        include_once "processors/save_template.processor.php";
    break;
    case 21:
        // get the delete processor
        include_once "processors/delete_template.processor.php";
    break;
    case 96:
        // get the duplicate template processor
        include_once "processors/duplicate_template.processor.php";
    break;
    case 117:
        // change the tv rank for selected template
        include_once "actions/mutate_template_tv_rank.dynamic.php";
        include_once "footer.inc.php";
        break;
/********************************************************************/
/* snippet management                                               */
/********************************************************************/
    case 22:
        // get the edit snippet action
        require_once('header.inc.php');
        include_once "actions/mutate_snippet.dynamic.php";
        include_once "footer.inc.php";
    break;
    case 23:
        // get the new snippet action
        require_once('header.inc.php');
        include_once "actions/mutate_snippet.dynamic.php";
        include_once "footer.inc.php";
    break;
    case 24:
        // get the save processor
        include_once "processors/save_snippet.processor.php";
    break;
    case 25:
        // get the delete processor
        include_once "processors/delete_snippet.processor.php";
    break;
    case 98:
        // get the duplicate processor
        include_once "processors/duplicate_snippet.processor.php";
    break;
/********************************************************************/
/* htmlsnippet management                                               */
/********************************************************************/
    case 78:
        // get the edit snippet action
        require_once('header.inc.php');
        include_once "actions/mutate_htmlsnippet.dynamic.php";
        include_once "footer.inc.php";
    break;
    case 77:
        // get the new snippet action
        require_once('header.inc.php');
        include_once "actions/mutate_htmlsnippet.dynamic.php";
        include_once "footer.inc.php";
    break;
    case 79:
        // get the save processor
        include_once "processors/save_htmlsnippet.processor.php";
    break;
    case 80:
        // get the delete processor
        include_once "processors/delete_htmlsnippet.processor.php";
    break;
    case 97:
        // get the duplicate processor
        include_once "processors/duplicate_htmlsnippet.processor.php";
    break;
/********************************************************************/
/* empty cache & synchronisation                                    */
/********************************************************************/
    case 26:
        // get the cache emptying processor
        require_once('header.inc.php');
        include_once "actions/refresh_site.dynamic.php";
        require_once('footer.inc.php');
    break;
/********************************************************************/
/* Module management                                                */
/********************************************************************/
    case 106:
        // get module management
        require_once('header.inc.php');
        include_once "actions/modules.static.php";
        include_once "footer.inc.php";
    break;
    case 107:
        // get the new module action
        require_once('header.inc.php');
        include_once "actions/mutate_module.dynamic.php";
        include_once "footer.inc.php";
    break;
    case 108:
        // get the edit module action
        require_once('header.inc.php');
        include_once "actions/mutate_module.dynamic.php";
        include_once "footer.inc.php";
    break;
    case 109:
        // get the save processor
        include_once "processors/save_module.processor.php";
    break;
    case 110:
        // get the delete processor
        include_once "processors/delete_module.processor.php";
    break;
    case 111:
        // get the duplicate processor
        include_once "processors/duplicate_module.processor.php";
    break;
    case 112:
        // execute/run the module
        include_once "processors/execute_module.processor.php";
    break;
    case 113:
        // get the module resources (dependencies) action
        require_once('header.inc.php');
        include_once "actions/mutate_module_resources.dynamic.php";
        include_once "footer.inc.php";
    break;
/********************************************************************/
/* plugin management                                                */
/********************************************************************/
    case 100:
        // change the plugin priority
        include_once "actions/mutate_plugin_priority.dynamic.php";
        include_once "footer.inc.php";
    break;
    case 101:
        // get the new plugin action
        require_once('header.inc.php');
        include_once "actions/mutate_plugin.dynamic.php";
        include_once "footer.inc.php";
    break;
    case 102:
        // get the edit plugin action
        require_once('header.inc.php');
        include_once "actions/mutate_plugin.dynamic.php";
        include_once "footer.inc.php";
    break;
    case 103:
        // get the save processor
        include_once "processors/save_plugin.processor.php";
    break;
    case 104:
        // get the delete processor
        include_once "processors/delete_plugin.processor.php";
    break;
    case 105:
        // get the duplicate processor
        include_once "processors/duplicate_plugin.processor.php";
    break;
    case 119:
        // get the purge processor
        include_once "processors/purge_plugin.processor.php";
    break;
/********************************************************************/
/* view phpinfo                                                     */
/********************************************************************/
    case 200:
        // show phpInfo
        if($modx->hasPermission('logs')) {
            require_once('header.inc.php');
            include_once "actions/phpinfo.static.php";
            include_once "footer.inc.php";
        }
    break;
/********************************************************************/
/* errorpage                                            */
/********************************************************************/
    case 29:
        // get the error page
        include_once "actions/error_dialog.static.php";
    break;
/********************************************************************/
/* file manager                                                     */
/********************************************************************/
    case 31:
        // get the page to manage files
        require_once('header.inc.php');
        include_once "actions/files.dynamic.php";
        include_once "footer.inc.php";
    break;
/********************************************************************/
/* access permissions                                               */
/********************************************************************/
    case 40:
        require_once('header.inc.php');
        include_once "actions/access_permissions.dynamic.php";
        include_once "footer.inc.php";
    break;
    case 91:
        require_once('header.inc.php');
        include_once "actions/web_access_permissions.dynamic.php";
        include_once "footer.inc.php";
    break;
/********************************************************************/
/* access groups processor                                          */
/********************************************************************/
    case 41:
        include_once "processors/access_groups.processor.php";
    break;
    case 92:
        include_once "processors/web_access_groups.processor.php";
    break;
/********************************************************************/
/* settings editor                                                  */
/********************************************************************/
    case 17:
        // get the settings editor
        require_once('header.inc.php');
        include_once "actions/mutate_settings.dynamic.php";
        include_once "footer.inc.php";
    break;
    case 118:
        // call settings ajax include
        ob_clean();
        include_once "includes/mutate_settings.ajax.php";
    break;
/********************************************************************/
/* save settings                                                    */
/********************************************************************/
    case 30:
        // get the save settings processor
        include_once "processors/save_settings.processor.php";
    break;
/********************************************************************/
/* Package Manager                                                  */
/********************************************************************/
    case 120:
        // Package Manager
        require_once('header.inc.php');
        require_once('actions/package_manager.php');
        require_once('footer.inc.php');
        break;
/********************************************************************/
/* system information                                               */
/********************************************************************/
    case 53:
        // get the settings editor
        require_once('header.inc.php');
        include_once "actions/sysinfo.static.php";
        include_once "footer.inc.php";
    break;
/********************************************************************/
/* optimise table                                               */
/********************************************************************/
    case 54:
        // get the table optimizer/truncate processor
        include_once "processors/optimize_table.processor.php";
    break;
/********************************************************************/
/* view logging                                                     */
/********************************************************************/
    case 13:
        // view logging
        require_once('header.inc.php');
        include_once "actions/logging.static.php";
        include_once "footer.inc.php";
    break;
/********************************************************************/
/* empty logs                                                       */
/********************************************************************/
    case 55:
        // get the settings editor
        include_once "processors/empty_table.processor.php";
    break;
/********************************************************************/
/* Empty recycle bin                                                */
/********************************************************************/
    case 64:
        // get the Recycle bin emptier
        include_once "processors/remove_content.processor.php";
    break;
/********************************************************************/
/* Remove locks                                                 */
/********************************************************************/
    case 67:
        // get the lock remover
        include_once "processors/remove_locks.processor.php";
    break;
/********************************************************************/
/* Site schedule                                                    */
/********************************************************************/
    case 70:
        // get the schedule page
        require_once('header.inc.php');
        include_once "actions/site_schedule.static.php";
        include_once "footer.inc.php";
    break;
/********************************************************************/
/* Search                                                           */
/********************************************************************/
    case 71:
        // get the search page
        require_once('header.inc.php');
        include_once "actions/search.static.php";
        include_once "footer.inc.php";
    break;
/********************************************************************/
/* Add weblink                                                          */
/********************************************************************/
    case 72:
        // get the weblink page
        require_once('header.inc.php');
        include_once "actions/mutate_content.dynamic.php";
        include_once "footer.inc.php";
    break;
/********************************************************************/
/* User management                                                  */
/********************************************************************/
    case 75:
        require_once('header.inc.php');
        include_once "actions/user_management.static.php";
        include_once "footer.inc.php";
    break;
    case 99:
        require_once('header.inc.php');
        include_once "actions/web_user_management.static.php";
        include_once "footer.inc.php";
    break;
    case 86:
        require_once('header.inc.php');
        include_once "actions/role_management.static.php";
        include_once "footer.inc.php";
    break;
/********************************************************************/
/* template/ snippet management                                                 */
/********************************************************************/
    case 76:
        require_once('header.inc.php');
        include_once "actions/resources.static.php";
        include_once "footer.inc.php";
    break;
/********************************************************************/
/* Resource Selector                                                    */
/********************************************************************/
    case 84:
        include_once "actions/resource_selector.static.php";
    break;
/********************************************************************/
/* Backup Manager                                                   */
/********************************************************************/
    case 93:
        # header and footer will be handled interally
        include_once "actions/bkmanager.static.php";
    break;
/********************************************************************/
/* Duplicate Document                                                   */
/********************************************************************/
    case 94:
        // get the duplicate processor
        include_once "processors/duplicate_content.processor.php";
    break;
/********************************************************************/
/* Import Document from file                                        */
/********************************************************************/
    case 95:
        require_once('header.inc.php');
        include_once "actions/import_site.static.php";
        include_once "footer.inc.php";
    break;
/********************************************************************/
/* Help                                                             */
/********************************************************************/
    case 9:
        // get the help page
        require_once('header.inc.php');
        include_once "actions/help.static.php";
        include_once "footer.inc.php";
    break;
/********************************************************************/
/* Template Variables - Based on Apodigm's Docvars                  */
/********************************************************************/
    case 300:
        // get the new document variable action
        require_once('header.inc.php');
        include_once "actions/mutate_tmplvars.dynamic.php";
        include_once "footer.inc.php";
    break;
    case 301:
        // get the edit document variable action
        require_once('header.inc.php');
        include_once "actions/mutate_tmplvars.dynamic.php";
        include_once "footer.inc.php";
    break;
    case 302:
        // get the save processor
        include_once "processors/save_tmplvars.processor.php";
    break;
    case 303:
        // get the delete processor
        include_once "processors/delete_tmplvars.processor.php";
    break;
    case 304:
        // get the duplicate processor
        include_once "processors/duplicate_tmplvars.processor.php";
    break;

/********************************************************************/
/* Event viewer: show event message log                             */
/********************************************************************/
    case 114:
        // get event logs
        require_once('header.inc.php');
        include_once "actions/eventlog.dynamic.php";
        include_once "footer.inc.php";
    break;
    case 115:
        // get event log details viewer
        require_once('header.inc.php');
        include_once "actions/eventlog_details.dynamic.php";
        include_once "footer.inc.php";
    break;
    case 116:
        // get the event log delete processor
        include_once "processors/delete_eventlog.processor.php";
    break;

    case 501:
        //delete category
        include_once "processors/delete_category.processor.php";
    break;

/********************************************************************/
/* Obsolete actions                                                 */
/********************************************************************/
    case 10: // Messages page
    case 65: // Delete msg
    case 66: // Send msg
        require_once('header.inc.php');
        echo "
            <div class='sectionHeader'>{$_lang['function_no_longer_impl']}</div>
			<div class='sectionBody'>
                <p>{$_lang['function_no_longer_impl_message']}</p>
            </div>";
        include_once "footer.inc.php";
		break;
/********************************************************************/
/* default action: show not implemented message                     */
/********************************************************************/
    default :
        // say that what was requested doesn't do anything yet
        require_once('header.inc.php');
        echo "
            <div class='sectionHeader'>{$_lang['functionnotimpl']}</div>
			<div class='sectionBody'>
                <p>{$_lang['functionnotimpl_message']}</p>
            </div>";
        include_once "footer.inc.php";
}

/********************************************************************/
// log action, unless it's a frame request
if($action!=1 && $action!=7 && $action!=2) {
    include_once "log.class.inc.php";
    $log = new logHandler;
    $log->initAndWriteLog();
}
/********************************************************************/
// show debug
unset($_SESSION['itemname']); // clear this, because it's only set for logging purposes
include_once "debug.inc.php";
