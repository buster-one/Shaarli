<?php
namespace Shaarli;

require_once 'tests/utils/FakeConfigManager.php';
use \PHPUnit\Framework\TestCase;

/**
 * Test coverage for LoginManager
 */
class LoginManagerTest extends TestCase
{
    protected $configManager = null;
    protected $loginManager = null;
    protected $banFile = 'sandbox/ipbans.php';
    protected $logFile = 'sandbox/shaarli.log';
    protected $globals = [];
    protected $ipAddr = '127.0.0.1';
    protected $server = [];
    protected $trustedProxy = '10.1.1.100';

    /**
     * Prepare or reset test resources
     */
    public function setUp()
    {
        if (file_exists($this->banFile)) {
            unlink($this->banFile);
        }

        $this->configManager = new \FakeConfigManager([
            'resource.ban_file' => $this->banFile,
            'resource.log' => $this->logFile,
            'security.ban_after' => 4,
            'security.ban_duration' => 3600,
            'security.trusted_proxies' => [$this->trustedProxy],
        ]);

        $this->globals = &$GLOBALS;
        unset($this->globals['IPBANS']);

        $this->loginManager = new LoginManager($this->globals, $this->configManager);
        $this->server['REMOTE_ADDR'] = $this->ipAddr;
    }

    /**
     * Wipe test resources
     */
    public function tearDown()
    {
        unset($this->globals['IPBANS']);
    }

    /**
     * Instantiate a LoginManager and load ban records
     */
    public function testReadBanFile()
    {
        file_put_contents(
            $this->banFile,
            "<?php\n\$GLOBALS['IPBANS']=array('FAILURES' => array('127.0.0.1' => 99));\n?>"
        );
        new LoginManager($this->globals, $this->configManager);
        $this->assertEquals(99, $this->globals['IPBANS']['FAILURES']['127.0.0.1']);
    }

    /**
     * Record a failed login attempt
     */
    public function testHandleFailedLogin()
    {
        $this->loginManager->handleFailedLogin($this->server);
        $this->assertEquals(1, $this->globals['IPBANS']['FAILURES'][$this->ipAddr]);

        $this->loginManager->handleFailedLogin($this->server);
        $this->assertEquals(2, $this->globals['IPBANS']['FAILURES'][$this->ipAddr]);
    }

    /**
     * Record a failed login attempt - IP behind a trusted proxy
     */
    public function testHandleFailedLoginBehindTrustedProxy()
    {
        $server = [
            'REMOTE_ADDR' => $this->trustedProxy,
            'HTTP_X_FORWARDED_FOR' => $this->ipAddr,
        ];
        $this->loginManager->handleFailedLogin($server);
        $this->assertEquals(1, $this->globals['IPBANS']['FAILURES'][$this->ipAddr]);

        $this->loginManager->handleFailedLogin($server);
        $this->assertEquals(2, $this->globals['IPBANS']['FAILURES'][$this->ipAddr]);
    }

    /**
     * Record a failed login attempt - IP behind a trusted proxy but not forwarded
     */
    public function testHandleFailedLoginBehindTrustedProxyNoIp()
    {
        $server = [
            'REMOTE_ADDR' => $this->trustedProxy,
        ];
        $this->loginManager->handleFailedLogin($server);
        $this->assertFalse(isset($this->globals['IPBANS']['FAILURES'][$this->ipAddr]));

        $this->loginManager->handleFailedLogin($server);
        $this->assertFalse(isset($this->globals['IPBANS']['FAILURES'][$this->ipAddr]));
    }

    /**
     * Record a failed login attempt and ban the IP after too many failures
     */
    public function testHandleFailedLoginBanIp()
    {
        $this->loginManager->handleFailedLogin($this->server);
        $this->assertEquals(1, $this->globals['IPBANS']['FAILURES'][$this->ipAddr]);
        $this->assertTrue($this->loginManager->canLogin($this->server));

        $this->loginManager->handleFailedLogin($this->server);
        $this->assertEquals(2, $this->globals['IPBANS']['FAILURES'][$this->ipAddr]);
        $this->assertTrue($this->loginManager->canLogin($this->server));

        $this->loginManager->handleFailedLogin($this->server);
        $this->assertEquals(3, $this->globals['IPBANS']['FAILURES'][$this->ipAddr]);
        $this->assertTrue($this->loginManager->canLogin($this->server));

        $this->loginManager->handleFailedLogin($this->server);
        $this->assertEquals(4, $this->globals['IPBANS']['FAILURES'][$this->ipAddr]);
        $this->assertFalse($this->loginManager->canLogin($this->server));

        // handleFailedLogin is not supposed to be called at this point:
        // - no login form should be displayed once an IP has been banned
        // - yet this could happen when using custom templates / scripts
        $this->loginManager->handleFailedLogin($this->server);
        $this->assertEquals(5, $this->globals['IPBANS']['FAILURES'][$this->ipAddr]);
        $this->assertFalse($this->loginManager->canLogin($this->server));
    }

    /**
     * Nothing to do
     */
    public function testHandleSuccessfulLogin()
    {
        $this->assertTrue($this->loginManager->canLogin($this->server));

        $this->loginManager->handleSuccessfulLogin($this->server);
        $this->assertTrue($this->loginManager->canLogin($this->server));
    }

    /**
     * Erase failure records after successfully logging in from this IP
     */
    public function testHandleSuccessfulLoginAfterFailure()
    {
        $this->loginManager->handleFailedLogin($this->server);
        $this->loginManager->handleFailedLogin($this->server);
        $this->assertEquals(2, $this->globals['IPBANS']['FAILURES'][$this->ipAddr]);
        $this->assertTrue($this->loginManager->canLogin($this->server));

        $this->loginManager->handleSuccessfulLogin($this->server);
        $this->assertTrue($this->loginManager->canLogin($this->server));
        $this->assertFalse(isset($this->globals['IPBANS']['FAILURES'][$this->ipAddr]));
        $this->assertFalse(isset($this->globals['IPBANS']['BANS'][$this->ipAddr]));
    }

    /**
     * The IP is not banned
     */
    public function testCanLoginIpNotBanned()
    {
        $this->assertTrue($this->loginManager->canLogin($this->server));
    }

    /**
     * The IP is banned
     */
    public function testCanLoginIpBanned()
    {
        // ban the IP for an hour
        $this->globals['IPBANS']['FAILURES'][$this->ipAddr] = 10;
        $this->globals['IPBANS']['BANS'][$this->ipAddr] = time() + 3600;

        $this->assertFalse($this->loginManager->canLogin($this->server));
    }

    /**
     * The IP is banned, and the ban duration is over
     */
    public function testCanLoginIpBanExpired()
    {
        // ban the IP for an hour
        $this->globals['IPBANS']['FAILURES'][$this->ipAddr] = 10;
        $this->globals['IPBANS']['BANS'][$this->ipAddr] = time() + 3600;
        $this->assertFalse($this->loginManager->canLogin($this->server));

        // lift the ban
        $this->globals['IPBANS']['BANS'][$this->ipAddr] = time() - 3600;
        $this->assertTrue($this->loginManager->canLogin($this->server));
    }
}
