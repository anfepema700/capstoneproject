<?php
/* vim: set expandtab sw=4 ts=4 sts=4: */
/**
 * Server config checks management
 *
 * @package PhpMyAdmin
 */
namespace PhpMyAdmin\Config;

use PhpMyAdmin\Config\ConfigFile;
use PhpMyAdmin\Config\Descriptions;
use PhpMyAdmin\Core;
use PhpMyAdmin\Sanitize;
use PhpMyAdmin\Setup\Index as SetupIndex;
use PhpMyAdmin\Url;
use PhpMyAdmin\Util;

/**
 * Performs various compatibility, security and consistency checks on current config
 *
 * Outputs results to message list, must be called between SetupIndex::messagesBegin()
 * and SetupIndex::messagesEnd()
 *
 * @package PhpMyAdmin
 */
class ServerConfigChecks
{
    /**
     * @var ConfigFile configurations being checked
     */
    protected $cfg;

    /**
     * Constructor.
     *
     * @param ConfigFile $cfg Configuration
     */
    public function __construct(ConfigFile $cfg)
    {
        $this->cfg = $cfg;
    }

    /**
     * Perform config checks
     *
     * @return void
     */
    public function performConfigChecks()
    {
        $blowfishSecret = $this->cfg->get('blowfish_secret');
        $blowfishSecretSet = false;
        $cookieAuthUsed = false;

        list($cookieAuthUsed, $blowfishSecret, $blowfishSecretSet)
            = $this->performConfigChecksServers(
                $cookieAuthUsed, $blowfishSecret, $blowfishSecretSet
            );

        $this->performConfigChecksCookieAuthUsed(
            $cookieAuthUsed, $blowfishSecretSet,
            $blowfishSecret
        );

        //
        // $cfg['AllowArbitraryServer']
        // should be disabled
        //
        if ($this->cfg->getValue('AllowArbitraryServer')) {
            $sAllowArbitraryServerWarn = sprintf(
                __(
                    'This %soption%s should be disabled as it allows attackers to '
                    . 'bruteforce login to any MySQL server. If you feel this is necessary, '
                    . 'use %srestrict login to MySQL server%s or %strusted proxies list%s. '
                    . 'However, IP-based protection with trusted proxies list may not be '
                    . 'reliable if your IP belongs to an ISP where thousands of users, '
                    . 'including you, are connected to.'
                ),
                '[a@' . Url::getCommon(array('page' => 'form', 'formset' => 'Features')) . '#tab_Security]',
                '[/a]',
                '[a@' . Url::getCommon(array('page' => 'form', 'formset' => 'Features')) . '#tab_Security]',
                '[/a]',
                '[a@' . Url::getCommon(array('page' => 'form', 'formset' => 'Features')) . '#tab_Security]',
                '[/a]'
            );
            SetupIndex::messagesSet(
                'notice',
                'AllowArbitraryServer',
                Descriptions::get('AllowArbitraryServer'),
                Sanitize::sanitize($sAllowArbitraryServerWarn)
            );
        }

        $this->performConfigChecksLoginCookie();

        $sDirectoryNotice = __(
            'This value should be double checked to ensure that this directory is '
            . 'neither world accessible nor readable or writable by other users on '
            . 'your server.'
        );

        //
        // $cfg['SaveDir']
        // should not be world-accessible
        //
        if ($this->cfg->getValue('SaveDir') != '') {
            SetupIndex::messagesSet(
                'notice',
                'SaveDir',
                Descriptions::get('SaveDir'),
                Sanitize::sanitize($sDirectoryNotice)
            );
        }

        //
        // $cfg['TempDir']
        // should not be world-accessible
        //
        if ($this->cfg->getValue('TempDir') != '') {
            SetupIndex::messagesSet(
                'notice',
                'TempDir',
                Descriptions::get('TempDir'),
                Sanitize::sanitize($sDirectoryNotice)
            );
        }

        $this->performConfigChecksZips();
    }

    /**
     * Check config of servers
     *
     * @param boolean $cookieAuthUsed    Cookie auth is used
     * @param string  $blowfishSecret    Blowfish secret
     * @param boolean $blowfishSecretSet Blowfish secret set
     *
     * @return array
     */
    protected function performConfigChecksServers(
        $cookieAuthUsed, $blowfishSecret,
        $blowfishSecretSet
    ) {
        $serverCnt = $this->cfg->getServerCount();
        for ($i = 1; $i <= $serverCnt; $i++) {
            $cookieAuthServer
                = ($this->cfg->getValue("Servers/$i/auth_type") == 'cookie');
            $cookieAuthUsed |= $cookieAuthServer;
            $serverName = $this->performConfigChecksServersGetServerName(
                $this->cfg->getServerName($i), $i
            );
            $serverName = htmlspecialchars($serverName);

            list($blowfishSecret, $blowfishSecretSet)
                = $this->performConfigChecksServersSetBlowfishSecret(
                    $blowfishSecret, $cookieAuthServer, $blowfishSecretSet
                );

            //
            // $cfg['Servers'][$i]['ssl']
            // should be enabled if possible
            //
            if (!$this->cfg->getValue("Servers/$i/ssl")) {
                $title = Descriptions::get('Servers/1/ssl') . " ($serverName)";
                SetupIndex::messagesSet(
                    'notice',
                    "Servers/$i/ssl",
                    $title,
                    __(
                        'You should use SSL connections if your database server '
                        . 'supports it.'
                    )
                );
            }
            $sSecurityInfoMsg = Sanitize::sanitize(sprintf(
                __(
                    'If you feel this is necessary, use additional protection settings - '
                    . '%1$shost authentication%2$s settings and %3$strusted proxies list%4%s. '
                    . 'However, IP-based protection may not be reliable if your IP belongs '
                    . 'to an ISP where thousands of users, including you, are connected to.'
                ),
                '[a@' . Url::getCommon(array('page' => 'servers', 'mode' => 'edit', 'id' => $i)) . '#tab_Server_config]',
                '[/a]',
                '[a@' . Url::getCommon(array('page' => 'form', 'formset' => 'Features')) . '#tab_Security]',
                '[/a]'
            ));

            //
            // $cfg['Servers'][$i]['auth_type']
            // warn about full user credentials if 'auth_type' is 'config'
            //
            if ($this->cfg->getValue("Servers/$i/auth_type") == 'config'
                && $this->cfg->getValue("Servers/$i/user") != ''
                && $this->cfg->getValue("Servers/$i/password") != ''
            ) {
                $title = Descriptions::get('Servers/1/auth_type')
                    . " ($serverName)";
                SetupIndex::messagesSet(
                    'notice',
                    "Servers/$i/auth_type",
                    $title,
                    Sanitize::sanitize(sprintf(
                        __(
                            'You set the [kbd]config[/kbd] authentication type and included '
                            . 'username and password for auto-login, which is not a desirable '
                            . 'option for live hosts. Anyone who knows or guesses your phpMyAdmin '
                            . 'URL can directly access your phpMyAdmin panel. Set %1$sauthentication '
                            . 'type%2$s to [kbd]cookie[/kbd] or [kbd]http[/kbd].'
                        ),
                        '[a@' . Url::getCommon(array('page' => 'servers', 'mode' => 'edit', 'id' => $i)) . '#tab_Server]',
                        '[/a]'
                    ))
                    . ' ' . $sSecurityInfoMsg
                );
            }

            //
            // $cfg['Servers'][$i]['AllowRoot']
            // $cfg['Servers'][$i]['AllowNoPassword']
            // serious security flaw
            //
            if ($this->cfg->getValue("Servers/$i/AllowRoot")
                && $this->cfg->getValue("Servers/$i/AllowNoPassword")
            ) {
                $title = Descriptions::get('Servers/1/AllowNoPassword')
                    . " ($serverName)";
                SetupIndex::messagesSet(
                    'notice',
                    "Servers/$i/AllowNoPassword",
                    $title,
                    __('You allow for connecting to the server without a password.')
                    . ' ' . $sSecurityInfoMsg
                );
            }
        }
        return array($cookieAuthUsed, $blowfishSecret, $blowfishSecretSet);
    }

    /**
     * Set blowfish secret
     *
     * @param string  $blowfishSecret    Blowfish secret
     * @param boolean $cookieAuthServer  Cookie auth is used
     * @param boolean $blowfishSecretSet Blowfish secret set
     *
     * @return array
     */
    protected function performConfigChecksServersSetBlowfishSecret(
        $blowfishSecret, $cookieAuthServer, $blowfishSecretSet
    ) {
        if ($cookieAuthServer && $blowfishSecret === null) {
            $blowfishSecretSet = true;
            $this->cfg->set('blowfish_secret', Util::generateRandom(32));
        }
        return array($blowfishSecret, $blowfishSecretSet);
    }

    /**
     * Define server name
     *
     * @param string $serverName Server name
     * @param int    $serverId   Server id
     *
     * @return string Server name
     */
    protected function performConfigChecksServersGetServerName(
        $serverName, $serverId
    ) {
        if ($serverName == 'localhost') {
            $serverName .= " [$serverId]";
            return $serverName;
        }
        return $serverName;
    }

    /**
     * Perform config checks for zip part.
     *
     * @return void
     */
    protected function performConfigChecksZips() {
        $this->performConfigChecksServerGZipdump();
        $this->performConfigChecksServerBZipdump();
        $this->performConfigChecksServersZipdump();
    }

    /**
     * Perform config checks for zip part.
     *
     * @return void
     */
    protected function performConfigChecksServersZipdump() {
        //
        // $cfg['ZipDump']
        // requires zip_open in import
        //
        if ($this->cfg->getValue('ZipDump') && !$this->functionExists('zip_open')) {
            SetupIndex::messagesSet(
                'error',
                'ZipDump_import',
                Descriptions::get('ZipDump'),
                Sanitize::sanitize(sprintf(
                    __(
                        '%sZip decompression%s requires functions (%s) which are unavailable '
                        . 'on this system.'
                    ),
                    '[a@' . Url::getCommon(array('page' => 'form', 'formset' => 'Features')) . '#tab_Import_export]',
                    '[/a]',
                    'zip_open'
                ))
            );
        }

        //
        // $cfg['ZipDump']
        // requires gzcompress in export
        //
        if ($this->cfg->getValue('ZipDump') && !$this->functionExists('gzcompress')) {
            SetupIndex::messagesSet(
                'error',
                'ZipDump_export',
                Descriptions::get('ZipDump'),
                Sanitize::sanitize(sprintf(
                    __(
                        '%sZip compression%s requires functions (%s) which are unavailable on '
                        . 'this system.'
                    ),
                    '[a@' . Url::getCommon(array('page' => 'form', 'formset' => 'Features')) . '#tab_Import_export]',
                    '[/a]',
                    'gzcompress'
                ))
            );
        }
    }

    /**
     * Check config of servers
     *
     * @param boolean $cookieAuthUsed    Cookie auth is used
     * @param boolean $blowfishSecretSet Blowfish secret set
     * @param string  $blowfishSecret    Blowfish secret
     *
     * @return array
     */
    protected function performConfigChecksCookieAuthUsed(
        $cookieAuthUsed, $blowfishSecretSet,
        $blowfishSecret
    ) {
        //
        // $cfg['blowfish_secret']
        // it's required for 'cookie' authentication
        //
        if ($cookieAuthUsed) {
            if ($blowfishSecretSet) {
                // 'cookie' auth used, blowfish_secret was generated
                SetupIndex::messagesSet(
                    'notice',
                    'blowfish_secret_created',
                    Descriptions::get('blowfish_secret'),
                    Sanitize::sanitize(__(
                        'You didn\'t have blowfish secret set and have enabled '
                        . '[kbd]cookie[/kbd] authentication, so a key was automatically '
                        . 'generated for you. It is used to encrypt cookies; you don\'t need to '
                        . 'remember it.'
                    ))
                );
            } else {
                $blowfishWarnings = array();
                // check length
                if (strlen($blowfishSecret) < 32) {
                    // too short key
                    $blowfishWarnings[] = __(
                        'Key is too short, it should have at least 32 characters.'
                    );
                }
                // check used characters
                $hasDigits = (bool)preg_match('/\d/', $blowfishSecret);
                $hasChars = (bool)preg_match('/\S/', $blowfishSecret);
                $hasNonword = (bool)preg_match('/\W/', $blowfishSecret);
                if (!$hasDigits || !$hasChars || !$hasNonword) {
                    $blowfishWarnings[] = Sanitize::sanitize(
                        __(
                            'Key should contain letters, numbers [em]and[/em] '
                            . 'special characters.'
                        )
                    );
                }
                if (!empty($blowfishWarnings)) {
                    SetupIndex::messagesSet(
                        'error',
                        'blowfish_warnings' . count($blowfishWarnings),
                        Descriptions::get('blowfish_secret'),
                        implode('<br />', $blowfishWarnings)
                    );
                }
            }
        }
    }

    /**
     * Check configuration for login cookie
     *
     * @return void
     */
    protected function performConfigChecksLoginCookie() {
        //
        // $cfg['LoginCookieValidity']
        // value greater than session.gc_maxlifetime will cause
        // random session invalidation after that time
        $loginCookieValidity = $this->cfg->getValue('LoginCookieValidity');
        if ($loginCookieValidity > ini_get('session.gc_maxlifetime')
        ) {
            SetupIndex::messagesSet(
                'error',
                'LoginCookieValidity',
                Descriptions::get('LoginCookieValidity'),
                Sanitize::sanitize(sprintf(
                    __(
                        '%1$sLogin cookie validity%2$s greater than %3$ssession.gc_maxlifetime%4$s may '
                        . 'cause random session invalidation (currently session.gc_maxlifetime '
                        . 'is %5$d).'
                    ),
                    '[a@' . Url::getCommon(array('page' => 'form', 'formset' => 'Features')) . '#tab_Security]',
                    '[/a]',
                    '[a@' . Core::getPHPDocLink('session.configuration.php#ini.session.gc-maxlifetime') . ']',
                    '[/a]',
                    ini_get('session.gc_maxlifetime')
                ))
            );
        }

        //
        // $cfg['LoginCookieValidity']
        // should be at most 1800 (30 min)
        //
        if ($loginCookieValidity > 1800) {
            SetupIndex::messagesSet(
                'notice',
                'LoginCookieValidity',
                Descriptions::get('LoginCookieValidity'),
                Sanitize::sanitize(sprintf(
                    __(
                        '%sLogin cookie validity%s should be set to 1800 seconds (30 minutes) '
                        . 'at most. Values larger than 1800 may pose a security risk such as '
                        . 'impersonation.'
                    ),
                    '[a@' . Url::getCommon(array('page' => 'form', 'formset' => 'Features')) . '#tab_Security]',
                    '[/a]'
                ))
            );
        }

        //
        // $cfg['LoginCookieValidity']
        // $cfg['LoginCookieStore']
        // LoginCookieValidity must be less or equal to LoginCookieStore
        //
        if (($this->cfg->getValue('LoginCookieStore') != 0)
            && ($loginCookieValidity > $this->cfg->getValue('LoginCookieStore'))
        ) {
            SetupIndex::messagesSet(
                'error',
                'LoginCookieValidity',
                Descriptions::get('LoginCookieValidity'),
                Sanitize::sanitize(sprintf(
                    __(
                        'If using [kbd]cookie[/kbd] authentication and %sLogin cookie store%s '
                        . 'is not 0, %sLogin cookie validity%s must be set to a value less or '
                        . 'equal to it.'
                    ),
                    '[a@' . Url::getCommon(array('page' => 'form', 'formset' => 'Features')) . '#tab_Security]',
                    '[/a]',
                    '[a@' . Url::getCommon(array('page' => 'form', 'formset' => 'Features')) . '#tab_Security]',
                    '[/a]'
                ))
            );
        }
    }

    /**
     * Check GZipDump configuration
     *
     * @return void
     */
    protected function performConfigChecksServerBZipdump()
    {
        //
        // $cfg['BZipDump']
        // requires bzip2 functions
        //
        if ($this->cfg->getValue('BZipDump')
            && (!$this->functionExists('bzopen') || !$this->functionExists('bzcompress'))
        ) {
            $functions = $this->functionExists('bzopen')
                ? '' :
                'bzopen';
            $functions .= $this->functionExists('bzcompress')
                ? ''
                : ($functions ? ', ' : '') . 'bzcompress';
            SetupIndex::messagesSet(
                'error',
                'BZipDump',
                Descriptions::get('BZipDump'),
                Sanitize::sanitize(
                    sprintf(
                         __(
                            '%1$sBzip2 compression and decompression%2$s requires functions (%3$s) which '
                            . 'are unavailable on this system.'
                        ),
                        '[a@' . Url::getCommon(array('page' => 'form', 'formset' => 'Features')) . '#tab_Import_export]',
                        '[/a]',
                        $functions
                    )
                )
            );
        }
    }

    /**
     * Check GZipDump configuration
     *
     * @return void
     */
    protected function performConfigChecksServerGZipdump()
    {
        //
        // $cfg['GZipDump']
        // requires zlib functions
        //
        if ($this->cfg->getValue('GZipDump')
            && (!$this->functionExists('gzopen') || !$this->functionExists('gzencode'))
        ) {
            SetupIndex::messagesSet(
                'error',
                'GZipDump',
                Descriptions::get('GZipDump'),
                Sanitize::sanitize(sprintf(
                    __(
                        '%1$sGZip compression and decompression%2$s requires functions (%3$s) which '
                        . 'are unavailable on this system.'
                    ),
                    '[a@' . Url::getCommon(array('page' => 'form', 'formset' => 'Features')) . '#tab_Import_export]',
                    '[/a]',
                    'gzencode'
                ))
            );
        }
    }

    /**
     * Wrapper around function_exists to allow mock in test
     *
     * @param string $name Function name
     *
     * @return boolean
     */
    protected function functionExists($name)
    {
        return function_exists($name);
    }
}
