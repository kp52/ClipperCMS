<?php
if(!defined('IN_MANAGER_MODE') || IN_MANAGER_MODE != 'true') exit();

if (isset($_GET['ma'])) return; // EARLY RETURN !! For ajax.

// display system alert window if messages are available
if (count($SystemAlertMsgQueque)>0) {
	include "sysalert.display.inc.php";
}
?>
</body>
</html>
<!-- end footer -->

