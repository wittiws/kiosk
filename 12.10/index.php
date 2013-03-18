<?php
/**
 * Witti Kiosk
 * http://www.witti.ws/project/witti-kiosk
 * 
 * Copyright (c) 2013, Greg Payne
 * Dual licensed under the MIT and GPL licenses.
 * 
 * THE SOFTWARE IS PROVIDED “AS IS”, WITHOUT WARRANTY OF ANY KIND, EXPRESS OR 
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, 
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL 
 * GREG PAYNE OR ANY OTHER CONTRIBUTOR BE LIABLE FOR ANY CLAIM, DAMAGES OR 
 * OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, 
 * ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER 
 * DEALINGS IN THE SOFTWARE.
 */

$defaults = array(
  'home' => 'http://www.witti.ws/project/witti-kiosk?utm_campaign=KioskHomePage',
  'system' => 'http://kiosk.witti.ws/12.10/index.php?dump=source',
);
if (!isset($conf)) {
  $conf = $defaults;
}

// Do not allow this to be called on the web without an appropriate query string.
if (isset($_SERVER['REQUEST_METHOD'])) {
  if (isset($_GET['dump']) && $_GET['dump'] === 'source') {
    header("Cache-Control: max-age=300");
    foreach ($conf as $k => $v) {
      if (isset($_GET[$k])) {
        $conf[$k] = $_GET[$k];
        settype($conf[$k], gettype($v));
      }
    }
    $conf['system'] = $_SERVER['SCRIPT_URI'] . '?' . $_SERVER['QUERY_STRING'];
    echo "<?php \n/* The signature will track when the script has been updated. */\n";
    echo '   define("KIOSK_SIGNATURE", "' . md5_file(__FILE__) . md5(serialize($conf)) . '");' . "\n";
    echo "\n/**\n * The configuration below allows customization of the script.\n";
    echo " * The unmodified script allows you to manage this via query string.\n";
    echo " * THIS VARIABLE IS USER-GENERATED!\n */\n";
    echo '$conf = ' . var_export($conf, TRUE) . ";\n";
    echo "\n\n/* END OF CONFIGURATION. */\n?>";
    readfile(__FILE__);
  }
  elseif (isset($_GET['err']) && $_GET['err'] == 404) {
    header($_SERVER['SERVER_PROTOCOL']." 404 Not Found", true, 404);
    echo "Page Not Found.";
  }
  else {
    // Redirect the kiosk (or any other invalid request) directly to the evals page.
    header("Location: $conf[home]");
  }
  exit;
}
elseif (is_dir('/drive') || is_dir('/go')) {
  echo "Error: this script should not be called on some systems.\n";
  exit;
}

// Configure the order of the update steps.
$update_steps = array(
  'startup_verify' => "Verify configuration change",
  'update_conf_files' => "Update configuration files",
  'cleanup_xsessions' => "Remove unwanted xsession configurations",
  'cleanup_exit' => "Final steps",
);
$conf['home'] = escapeshellarg($conf['home']);
define('KIOSK_CONF_SIGNATURE_PATH', '/usr/share/xsessions/kiosk.md5');
define('KIOSK_CONF_FILES', <<<EOF
==== The crontab will automatically update the system every hour.
==== 700 root.root /root/crontab.txt
HOME=/root/
SHELL=/bin/bash
PATH=/sbin:/bin:/usr/sbin:/usr/bin
    
*/5 * * * * /usr/sbin/kiosk-update

    
==== Disable automatic updates.
==== http://hashprompt.blogspot.com/2012/03/turn-off-automatic-updates-in-ubuntu.html
==== 644 root.root /etc/apt/apt.conf.d/10periodic
APT::Periodic::Update-Package-Lists "0";
APT::Periodic::Download-Upgradeable-Packages "0";
APT::Periodic::AutocleanInterval "0";

    
==== Disable guest account and switch from lightdm-gtk-greeter to unity-greeter
==== http://www.ubuntugeek.com/ubuntu-tiphow-to-disable-guest-account-in-ubuntu-12-04precise.html
==== Bug: https://bugs.launchpad.net/ubuntu/+source/lightdm/+bug/902852
==== 644 root.root /etc/lightdm/lightdm.conf
[SeatDefaults]
user-session=Kiosk
greeter-session=unity-greeter
greeter-hide-users=true
allow-guest=false
autologin-session=Kiosk
autologin-user=kiosk
autologin-user-timeout=0
pam-service=lightdm-autologin

    
==== 644 root.root /usr/share/xsessions/Kiosk.desktop
[Desktop Entry]
Encoding=UTF-8
Name=Kiosk
Comment=Chromium Kiosk Mode
Exec=/usr/share/xsessions/kiosk-main.php
Type=Application


==== 755 kiosk.kiosk /usr/share/xsessions/kiosk-main.php
#!/usr/bin/php
<?php
\$who = trim(`whoami`);
if (\$who == 'kiosk') {
    // Background processes are tricky in php.
    // http://ca.php.net/manual/en/function.exec.php
    \$foo = null;
    proc_close(proc_open("xscreensaver -nosplash &", array(), \$foo));
    proc_close(proc_open("xscreensaver-command -watch | php -R 'if(preg_match(\"/^BLANK|^LOCK/\", \\\$argn))system(\"killall chromium-browser\");' &", array(), \$foo));
    while (TRUE) {
        include "/usr/share/xsessions/kiosk-sub.php";
    }
    system("lxsession-logout");
}
else {
    system("/usr/bin/startlubuntu");
}


==== The kiosk app loop calls a sub-script so that the sub-script
==== can be updated dynamically. Otherwise, changes are only applied
==== upon reboot.
==== 755 kiosk.kiosk /usr/share/xsessions/kiosk-sub.php
<?php
if (is_dir("/home/kiosk/.config/chromium/Default")) {
  chdir("/home/kiosk/.config/chromium/Default");
    
  // Reset chrome (just in case they impacted something).
  system("rm -rf Archived* Cookies* Current* Extension* History* Last* Login* Network* Shortcuts* Top* Visited* Web*");

  // Load and patch the Preferences.
  preg_match('@^(\d+)x(\d+)\s@s', trim(`xrandr | egrep '\*'`), \$win);
  \$prefs = json_decode(file_get_contents('Preferences'));
  \$prefs->browser->window_placement->top = 0;
  \$prefs->browser->window_placement->left = 0;
  \$prefs->browser->window_placement->bottom = (int) \$win[2];
  \$prefs->browser->window_placement->right = (int) \$win[1];
  \$prefs->browser->window_placement->work_area_top = 0;
  \$prefs->browser->window_placement->work_area_left = 0;
  \$prefs->browser->window_placement->work_area_bottom = (int) \$win[2];
  \$prefs->browser->window_placement->work_area_right = (int) \$win[1];
  \$prefs->browser->window_placement->maximized = true;
  \$prefs->profile->exited_cleanly = true;
  \$prefs->profile->password_manager_enabled = false;
  \$prefs->sync = (object) array(
    'suppress_start' => true
  );
  file_put_contents('Preferences', json_encode(\$prefs, JSON_PRETTY_PRINT));
    
  // Load and patch the local state.
  \$local = json_decode(file_get_contents('../Local State'));
  \$local->{'show-first-run-bubble'} = false;
  file_put_contents('../Local State', json_encode(\$local, JSON_PRETTY_PRINT));
}

// Launch the browser
// Could add --incognito, --start-maximized and --kiosk
system("chromium-browser %u --start-maximized $conf[home]");
// WE DO NOT REACH HERE UNTIL CHROMIUM IS CLOSED.

// See whether there was a logout request.
if (!is_file('Current Tabs') || strpos(file_get_contents('Current Tabs'), 'chrome://logout') !== FALSE) {
    break;
}

    
==== 644 kiosk.kiosk /home/kiosk/.dmrc
[Desktop]
Session=Kiosk
    
    
==== 644 kiosk.kiosk /home/kiosk/.xscreensaver
timeout: 0:10:00
mode: blank

    
==== Why /usr/sbin/ http://www.pathname.com/fhs/pub/fhs-2.3.html#PURPOSE20
==== 755 root.root /usr/sbin/kiosk-update
#!/bin/bash
/usr/bin/wget -q -O - "$conf[system]" | /usr/bin/php
/usr/bin/crontab /root/crontab.txt

EOF
);

function startup_verify() {
  $who = trim(`whoami`);
  if ($who !== 'root') {
    update_message("This command requires root access.");
    exit;
  }
  if (is_file(KIOSK_CONF_SIGNATURE_PATH) && file_get_contents(KIOSK_CONF_SIGNATURE_PATH) === KIOSK_SIGNATURE) {
    update_message("No change to the configuration.");
    exit;
  }
  
  // Make sure that the system is clean.
  if (trim(`which unity-greeter`) === '') {
    system("apt-get update");
    system("apt-get install -y unity-greeter");
  }
  if (trim(`which mtpaint`) !== '') {
    system("apt-get purge -y blueman ace-of-penguins pidgin simple-scan sylpheed transmission-gtk mtpaint");
    system("apt-get autoremove -y");
    system("apt-get autoclean -y");
  }
  
  // Download the chromium pdf viewer.
  $so = '/usr/lib/chromium-browser/libpdf.so';
  if (!is_file($so)) {
    chdir("/tmp");
    system("apt-get install binutils -y");
    system("wget -O /tmp/chrome.deb https://dl-ssl.google.com/linux/direct/google-chrome-stable_current_i386.deb");
    
    // Copy lib to chromium.
    // https://wiki.archlinux.org/index.php/Chromium#Open_PDF_files_inside_Chromium
    // http://gordonazmo.wordpress.com/2010/11/02/how-to-enable-googles-pdf-plugin-in-chromium/
    system("ar vx chrome.deb");
    system("tar --lzma -xvf data.tar.lzma");
    copy("opt/google/chrome/libpdf.so", $so);
    system("rm -rf /tmp/*");
  }
  system("chmod 777 /tmp");
  
  // Confirm the creation of the kiosk user.
  update_message("Verify the kiosk user");
  if (!is_dir('/home/kiosk')) {
    update_message("Create the kiosk user");
    system("useradd --home-dir /home/kiosk --create-home kiosk");
  }
  system("usermod --password kiosk --groups netdev,nopasswdlogin --shell /bin/false kiosk");
}

function update_conf_files() {
  $files = explode('====', KIOSK_CONF_FILES);
  array_shift($files);
  foreach ($files as $file) {
    $file = preg_replace("@[\r\n]+@s", "\n", $file);
    list($path, $contents) = explode("\n", $file, 2);
    if (empty($contents)) {
      update_message($path, 1);
    }
    else {
      list($mode, $owner, $path) = explode(' ', trim($path), 3);
      update_message("Update $path ($mode:$owner)", 2);
      file_put_contents($path, $contents);
      $path = escapeshellarg($path);
      system("chown $owner $path; chmod $mode $path");
    }
  }
}

function cleanup_xsessions() {
  chdir("/usr/share/xsessions");
  foreach (glob("*.desktop") as $path) {
    if ($path != 'Kiosk.desktop') {
      update_message($path);
      unlink($path);
    }
  }
}

function cleanup_exit() {
  $first_run = !is_file(KIOSK_CONF_SIGNATURE_PATH);
  file_put_contents(KIOSK_CONF_SIGNATURE_PATH, KIOSK_SIGNATURE);
  
  // Ensure that the file is created now to avoid infinite recursion.
  if ($first_run && is_file(KIOSK_CONF_SIGNATURE_PATH)) {
    system('kiosk-update');
  }
}

function update_message($msg, $indent = 0) {
  $msg = trim($msg);
  echo str_repeat('   ', max($indent, 0)) . "$msg\n";
}

foreach ($update_steps as $fnc => $msg) {
  update_message($msg);
  $fnc();
  update_message('done', 1);
}