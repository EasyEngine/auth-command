<?php
/**
 * Behat tests.
 *
 * @package ee-cli
 */

/* Start: Loading required files to enable EE::launch() in tests. */
define( 'EE_ROOT', __DIR__ . '/../..' );

include_once( EE_ROOT . '/php/class-ee.php' );
include_once( EE_ROOT . '/php/EE/Runner.php' );
include_once( EE_ROOT . '/php/utils.php' );

define( 'EE', true );
define( 'EE_VERSION', trim( file_get_contents( EE_ROOT . '/VERSION' ) ) );

$root_alias = '/opt';
if ( 'Darwin' === php_uname( 's' ) ) {
	$sys_name   = posix_getpwuid(posix_geteuid())['name'];
	if ( ! empty( $sys_name ) ) {
		$root_alias = '/Users/' . $sys_name;
	}
}
// site-command's autoloaded site-command.php may already define it.
if ( ! defined( 'EE_ROOT_DIR' ) ) {
	define( 'EE_ROOT_DIR', $root_alias . '/easyengine' );
}

require_once EE_ROOT . '/php/bootstrap.php';

if ( ! class_exists( 'EE\Runner' ) ) {
	require_once EE_ROOT . '/php/EE/Runner.php';
}

if ( ! class_exists( 'EE\Configurator' ) ) {
	require_once EE_ROOT . '/php/EE/Configurator.php';
}

$logger_dir = EE_ROOT . '/php/EE/Loggers';
$iterator   = new \DirectoryIterator( $logger_dir );

// Make sure the base class is declared first.
include_once "$logger_dir/Base.php";

foreach ( $iterator as $filename ) {
	if ( '.php' !== pathinfo( $filename, PATHINFO_EXTENSION ) ) {
		continue;
	}

	include_once "$logger_dir/$filename";
}
$runner = \EE::get_runner();
$runner->init_logger();
$runner->init_config();
/* End. Loading required files to enable EE::launch() in tests. */

use Behat\Behat\Context\Context;
use Behat\Behat\Hook\Scope\AfterFeatureScope;
use Behat\Behat\Hook\Scope\AfterScenarioScope;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Behat\Behat\Hook\Scope\BeforeStepScope;

use Behat\Gherkin\Node\PyStringNode,
	Behat\Gherkin\Node\TableNode;

define( 'EE_SITE_ROOT', EE_ROOT_DIR . '/sites' );
// Only Runner::init_ee() defines it, and the context never runs that.
if ( ! defined( 'EE_PROXY_TYPE' ) ) {
	define( 'EE_PROXY_TYPE', 'services_global-nginx-proxy_1' );
}

class FeatureContext implements Context
{
	/**
	 * Whether this run built ee.phar/ee-old.phar, so the cleanup only removes its own files.
	 *
	 * @var bool
	 */
	private static $phar_built = false;

	public $command;
	public $commands = [];
	public $webroot_path;
	public $ee_path;

	/**
	 * Every site this suite creates starts with this prefix. Cleanup only ever touches these sites.
	 */
	const SITE_PREFIX = 'bh-auth-command-';

	/**
	 * Initializes context.
	 */
	public function __construct()
	{
		$this->commands = [];
		$this->ee_path = getcwd();
	}

	/**
	 * @Given ee phar is generated
	 */
	public function eePharIsPresent()
	{
		// Checks if phar already exists, replaces it
		if(file_exists('ee-old.phar')) {
			// Below exec call is required as currenly `ee cli update` is ran with root
			// which updates ee.phar with root privileges.
			exec("sudo rm ee.phar");
			copy('ee-old.phar','ee.phar');
			self::$phar_built = true;
			return 0;
		}
		exec("php -dphar.readonly=0 utils/make-phar.php ee.phar", $output, $return_status);
		if (0 !== $return_status) {
			throw new Exception("Unable to generate phar" . $return_status);
		}

		// Cache generaed phar as it is expensive to generate one
		copy('ee.phar','ee-old.phar');
		self::$phar_built = true;
	}

	/**
	 * @Given :command is installed
	 */
	public function isInstalled($command)
	{
		exec("type " . $command, $output, $return_status);
		if (0 !== $return_status) {
			throw new Exception($command . " is not installed! Exit code is:" . $return_status);
		}
	}

	/**
	 * Anchored (the upstream pattern matched any step containing a double-quoted string).
	 * The command cannot contain the quote character that wraps it.
	 *
	 * @When /^I run (?|'([^']*)'|"([^"]*)")$/
	 */
	public function iRun($command)
	{
		$this->commands[] = EE::launch( $this->replace_variables( $command ), false, true, self::secret_values() );
	}

	/**
	 * @Then return value should be 0
	 */
	public function returnValueShouldBe0()
	{
		if ( 0 !== $this->commands[0]->return_code ) {
			throw new Exception("Actual return code is not zero: \n" . $this->command);
		}
	}

	/**
	 * @Then return value of command :index should be 0
	 */
	public function returnValueOfCommandShouldBe0($index)
	{
		if ( 0 !== $this->commands[ $index - 1 ]->return_code ) {
			throw new Exception("Actual return code is not zero: \n" . $this->command);
		}
	}

	/**
	 * @Then After delay of :time seconds
	 */
	public function afterDelayOfSeconds( $time )
	{
		sleep( $time );
	}

	/**
	 * @Then /(STDOUT|STDERR) should return exactly/
	 */
	public function stdoutShouldReturnExactly($output_stream, PyStringNode $expected_output)
	{
		$command_output = $output_stream === "STDOUT" ? $this->commands[0]->stdout : $this->commands[0]->stderr;

		$command_output = str_replace(["\033[1;31m","\033[0m"],'',$command_output);

		if ( $expected_output->getRaw() !== trim($command_output)) {
			throw new Exception("Actual output is:\n" . $command_output);
		}
	}

	/**
	 * @Then /(STDOUT|STDERR) of command :index should return exactly/
	 */
	public function stdoutOfCommandShouldReturnExactly($output_stream, $index, PyStringNode $expected_output)
	{
		$command_output = $output_stream === "STDOUT" ? $this->commands[ $index - 1 ]->stdout : $this->commands[ $index - 1 ]->stderr;

		$command_output = str_replace(["\033[1;31m","\033[0m"],'',$command_output);

		if ( $expected_output->getRaw() !== trim($command_output)) {
			throw new Exception("Actual output is:\n" . $command_output);
		}
	}

	/**
	 * @Then /(STDOUT|STDERR) should return something like/
	 */
	public function stdoutShouldReturnSomethingLike($output_stream, PyStringNode $expected_output)
	{
		$command_output = $output_stream === "STDOUT" ? $this->commands[0]->stdout : $this->commands[0]->stderr;

		if (strpos($command_output, $expected_output->getRaw()) === false) {
			throw new Exception("Actual output is:\n" . $command_output);
		}
	}


	/**
	 * @Then /(STDOUT|STDERR) of command :index should return something like/
	 */
	public function stdoutOfCommandShouldReturnSomethingLike($output_stream, $index, PyStringNode $expected_output)
	{
		$command_output = $output_stream === "STDOUT" ? $this->commands[ $index - 1 ]->stdout : $this->commands[ $index - 1 ]->stderr;

		if (strpos($command_output, $expected_output->getRaw()) === false) {
			throw new Exception("Actual output is:\n" . $command_output);
		}
	}

	/**
	 * @Then The site :site should be :type multisite
	 */
	public function theSiteShouldBeMultisite( $site, $type )
	{
		$result = EE::launch( "cd " . EE_SITE_ROOT . "/$site && docker-compose exec --user='www-data' php sh -c 'wp config get SUBDOMAIN_INSTALL'", false, true );

		if( $result->stderr ) {
			throw new Exception("Found error while executing command: $result->stderr");
		}

		if($type === 'subdomain' && trim($result->stdout) !== '1') {
			throw new Exception("Expecting SUBDOMAIN_INSTALL to be 1. Got: $result->stdout");
		}
		else if($type === 'subdir' && trim($result->stdout) !== '') {
			throw new Exception("Expecting SUBDOMAIN_INSTALL to be empty. Got: $result->stdout");
		}
		else if($type !== 'subdomain' && $type !== 'subdir') {
			throw new Exception("Found unknown site type: $type");
		}
	}

	/**
	 * @Then The :site db entry should be removed
	 */
	public function theDbEntryShouldBeRemoved($site)
	{
		$out = shell_exec("sudo bin/ee site list");
		if (strpos($out, $site) !== false) {
			throw new Exception("$site db entry not been removed!");
		}

	}

	/**
	 * @Then The :site webroot should be removed
	 */
	public function theWebrootShouldBeRemoved($site)
	{
		if ( file_exists( EE_SITE_ROOT . '/' . $site ) ) {
			throw new Exception("Webroot has not been removed!");
		}
	}

	/**
	 * @Then Following containers of site :site should be removed:
	 */
	public function followingContainersOfSiteShouldBeRemoved($site, TableNode $table)
	{
		$containers = $table->getHash();
		$site_name = implode(explode('.', $site));

		foreach ($containers as $container) {

			$sevice = $container['container'];
			$container_name = $site_name . '_' . $sevice . '_1';

			exec("docker inspect -f '{{.State.Running}}' $container_name > /dev/null 2>&1", $exec_out, $return);
			if (!$return) {
				throw new Exception("$container_name has not been removed!");
			}
		}
	}

	/**
	 * @Then The site :site should have webroot
	 */
	public function theSiteShouldHaveWebroot($site)
	{
		if ( ! file_exists( EE_SITE_ROOT . '/' . $site ) ) {
			throw new Exception("Webroot has not been created!");
		}
	}

	/**
	 * @Then The site :site should have WordPress
	 */
	public function theSiteShouldHaveWordpress($site)
	{
		if ( ! file_exists( EE_SITE_ROOT . '/' . $site . '/app/wp-config.php' ) ) {
			throw new Exception("WordPress data not found!");
		}
	}

	/**
	 * @Then Request on :site should contain following headers:
	 */
	public function requestOnShouldContainFollowingHeaders($site, TableNode $table)
	{
		$url = 'http://' . $site;

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_HEADER, true);
		curl_setopt($ch, CURLOPT_NOBODY, true);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_VERBOSE, true);
		$headers = curl_exec($ch);

		curl_close($ch);

		$rows = $table->getHash();

		foreach ($rows as $row) {
			if (strpos($headers, $row['header']) === false) {
				throw new Exception("Unable to find " . $row['header'] . "\nActual output is : " . $headers);
			}
		}
	}

	/**
	 * @Then Request on :host with header :header should contain following headers:
	 */
	public function requestOnWithHeaderShouldContainFollowingHeaders($host, $header, TableNode $table)
	{

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $host);
		curl_setopt($ch, CURLOPT_HEADER, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, [ $header ]);
		curl_setopt($ch, CURLOPT_NOBODY, true);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_VERBOSE, true);
		$headers = curl_exec($ch);

		curl_close($ch);

		$rows = $table->getHash();

		foreach ($rows as $row) {
			if (strpos($headers, $row['header']) === false) {
				throw new Exception("Unable to find " . $row['header'] . "\nActual output is : " . $headers);
			}
		}
	}

	/**
	 * @Then Request on :host with resolve option :resolve should contain following headers:
	 */
	public function requestOnWithResolveOptionShouldContainFollowingHeaders($host, $resolve, TableNode $table)
	{

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $host);
		curl_setopt($ch, CURLOPT_HEADER, true);
		curl_setopt($ch, CURLOPT_NOBODY, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_RESOLVE, [ $resolve ]);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_VERBOSE, true);
		$headers = curl_exec($ch);
		curl_close($ch);

		$rows = $table->getHash();

		foreach ($rows as $row) {
			if (strpos($headers, $row['header']) === false) {
				throw new Exception("Unable to find " . $row['header'] . "\nActual output is : " . $headers);
			}
		}
	}

	/**
	 * @AfterScenario
	 */
	public function cleanupScenario(AfterScenarioScope $scope)
	{
		$this->commands = [];
		chdir($this->ee_path);
	}

	/**
	 * @Then There should be :expected_running_containers containers with labels
	 */
	public function thereShouldBeContainersWithLabel($expected_running_containers, PyStringNode $pyStringNode)
	{
		$labels = $pyStringNode->getStrings();
		$label_string = implode(' -f label=',$labels);

		$result = EE::launch( "docker ps -qf label=$label_string | wc -l", false, true );
		$running_containers = (int) trim($result->stdout);

		if((int) $expected_running_containers !== $running_containers) {
			throw new Exception("Expected $expected_running_containers running containers. Found: $running_containers");
		}
	}

	/**
	 * Deletes only this suite's sites (SITE_PREFIX) and whatever auth state they left behind.
	 * The upstream hook deleted every site on the machine.
	 *
	 * @AfterFeature
	 */
	public static function cleanup(AfterFeatureScope $scope)
	{
		self::cleanup_prefixed_sites();

		if ( self::$phar_built ) {
			foreach ( [ 'ee.phar', 'ee-old.phar' ] as $phar ) {
				if ( file_exists( $phar ) ) {
					unlink( $phar );
				}
			}
			self::$phar_built = false;
		}
	}

	/*
	 * =========================================================================
	 * Shared steps and helpers (added for auth-command).
	 *
	 * Generic enough to move to a shared context later. All of them act on the
	 * LAST command run in the scenario (the upstream steps above check the first
	 * one). Strings in commands, paths, PyStrings and tables may use {NAME}
	 * placeholders: PUBLIC_IP, EE_ROOT_DIR, EE_BIN, FRONTEND_SUBNET_IP and any
	 * value saved with one of the "I save ..." steps.
	 * =========================================================================
	 */

	/** Seconds an HTTP assertion keeps retrying (nginx reloads are asynchronous). */
	const HTTP_SETTLE_SECONDS = 10;

	/** @var array Scenario variables for {NAME} placeholders. */
	private $variables = [];

	/** @var string[] Tags of the running scenario, feature tags included. */
	private $scenario_tags = [];

	/** @var array|null Auth state captured before a @destructive scenario. */
	private $global_auth_snapshot = null;

	/** @var string[] Real passwords seen in auth_users, masked in failure reports. */
	private static $known_passwords = [];

	/** @var bool Whether the @destructive snapshot is still to be taken. */
	private $snapshot_due = false;

	/**
	 * Puts back a snapshot an interrupted run left behind and checks that non-@destructive
	 * scenarios don't touch global auth.
	 *
	 * @BeforeScenario
	 */
	public function rememberScenarioState(BeforeScenarioScope $scope)
	{
		$this->scenario_tags = array_merge( $scope->getFeature()->getTags(), $scope->getScenario()->getTags() );
		self::remember_passwords();

		$pending = self::read_pending_snapshot();
		if ( null !== $pending ) {
			fwrite( STDERR, sprintf( "Restoring the auth state saved at %s in %s (left by an interrupted run or a failed restore).\n", $pending['time'], self::snapshot_file() ) );
			self::restore_auth_state( $pending );
		}

		$this->snapshot_due = in_array( 'destructive', $this->scenario_tags, true );
		if ( ! $this->snapshot_due ) {
			self::assert_no_global_auth_commands( $scope );
		}
	}

	/**
	 * Snapshots the auth state of a @destructive scenario before its first step that isn't a
	 * "site exists" step, so what `ee site create` initialises in the Background (admin-tools auth,
	 * frontend-subnet whitelist) is kept. The snapshot stays on disk until it has been restored,
	 * so a killed run or a failed restore is repaired before the next scenario.
	 *
	 * @BeforeStep
	 */
	public function snapshotBeforeFirstAuthStep(BeforeStepScope $scope)
	{
		if ( ! $this->snapshot_due || preg_match( '/^the \w+ site "[^"]+" exists( with flags "[^"]*")?$/', $scope->getStep()->getText() ) ) {
			return;
		}
		$this->snapshot_due         = false;
		$this->global_auth_snapshot = self::capture_auth_state();
		self::write_pending_snapshot( $this->global_auth_snapshot );
	}

	/**
	 * Removes the auth rows and files of this suite's sites, then restores the snapshot of a
	 * @destructive scenario. Neither depends on the code under test.
	 *
	 * @AfterScenario
	 */
	public function resetAuthState(AfterScenarioScope $scope)
	{
		if ( null !== $this->global_auth_snapshot ) {
			$snapshot                   = $this->global_auth_snapshot;
			$this->global_auth_snapshot = null;
			self::restore_auth_state( $snapshot );

			return;
		}
		if ( self::reset_test_sites_auth() ) {
			self::reload_proxy();
		}
	}

	/* ----------------------------- Commands ------------------------------ */

	/**
	 * @When I run :command in the directory :dir
	 */
	public function iRunInTheDirectory($command, $dir)
	{
		$dir = $this->replace_variables( $dir );
		if ( ! is_dir( $dir ) ) {
			throw new Exception( "Directory $dir does not exist." );
		}
		// `cd` sets PWD like a login shell; ee resolves the site from the logical path (app/ is a symlink to the volume).
		$this->commands[] = EE::launch( 'cd ' . escapeshellarg( $dir ) . ' && ' . $this->replace_variables( $command ), false, true, self::secret_values() );
	}

	/**
	 * @Then the command should succeed
	 */
	public function theCommandShouldSucceed()
	{
		$result = $this->last_command();
		if ( 0 !== $result->return_code ) {
			throw new Exception( "Expected exit code 0.\n" . self::describe( $result ) );
		}
	}

	/**
	 * @Then the command should fail
	 */
	public function theCommandShouldFail()
	{
		$result = $this->last_command();
		if ( 0 === $result->return_code ) {
			throw new Exception( "Expected a non-zero exit code.\n" . self::describe( $result ) );
		}
	}

	/**
	 * @Then the exit code should be :code
	 */
	public function theExitCodeShouldBe($code)
	{
		$result = $this->last_command();
		if ( (int) $code !== $result->return_code ) {
			throw new Exception( "Expected exit code $code.\n" . self::describe( $result ) );
		}
	}

	/**
	 * @Then /^(STDOUT|STDERR) should contain:$/
	 */
	public function outputShouldContain($stream, PyStringNode $expected)
	{
		$expected = $this->replace_variables( $expected->getRaw() );
		if ( false === strpos( $this->output( $stream ), $expected ) ) {
			throw new Exception( "$stream does not contain:\n$expected\n" . self::describe( $this->last_command() ) );
		}
	}

	/**
	 * @Then /^(STDOUT|STDERR) should not contain:$/
	 */
	public function outputShouldNotContain($stream, PyStringNode $unexpected)
	{
		$unexpected = $this->replace_variables( $unexpected->getRaw() );
		if ( false !== strpos( $this->output( $stream ), $unexpected ) ) {
			throw new Exception( "$stream unexpectedly contains:\n$unexpected\n" . self::describe( $this->last_command() ) );
		}
	}

	/**
	 * @Then /^(STDOUT|STDERR) should be empty$/
	 */
	public function outputShouldBeEmpty($stream)
	{
		if ( '' !== trim( $this->output( $stream ) ) ) {
			throw new Exception( "$stream is not empty.\n" . self::describe( $this->last_command() ) );
		}
	}

	/**
	 * @Then /^(STDOUT|STDERR) should be exactly:$/
	 */
	public function outputShouldBeExactly($stream, PyStringNode $expected)
	{
		$expected = $this->replace_variables( $expected->getRaw() );
		if ( trim( $this->output( $stream ) ) !== trim( $expected ) ) {
			throw new Exception( "$stream is not exactly:\n$expected\n" . self::describe( $this->last_command() ) );
		}
	}

	/**
	 * The PyString is a full PCRE pattern including delimiters and modifiers.
	 *
	 * @Then /^(STDOUT|STDERR) should match:$/
	 */
	public function outputShouldMatch($stream, PyStringNode $pattern)
	{
		$pattern = $this->replace_variables( trim( $pattern->getRaw() ) );
		$matched = @preg_match( $pattern, $this->output( $stream ) );
		if ( false === $matched ) {
			throw new Exception( "Invalid regular expression: $pattern" );
		}
		if ( 1 !== $matched ) {
			throw new Exception( "$stream does not match $pattern\n" . self::describe( $this->last_command() ) );
		}
	}

	/**
	 * @Then STDOUT should be valid JSON
	 */
	public function stdoutShouldBeValidJson()
	{
		$this->json_output();
	}

	/**
	 * @Then the JSON output should have :count items
	 */
	public function theJsonOutputShouldHaveItems($count)
	{
		$items = $this->json_output();
		if ( count( $items ) !== (int) $count ) {
			throw new Exception( sprintf( "Expected %d JSON items, got %d.\n%s", $count, count( $items ), self::describe( $this->last_command() ) ) );
		}
	}

	/**
	 * Every table row must match at least one JSON item on the given columns.
	 *
	 * @Then the JSON output should contain items:
	 */
	public function theJsonOutputShouldContainItems(TableNode $table)
	{
		$items = $this->json_output();
		foreach ( $this->table_hash( $table ) as $expected ) {
			if ( null === self::find_matching_item( $items, $expected ) ) {
				throw new Exception( 'No JSON item matches ' . json_encode( $expected ) . "\n" . self::describe( $this->last_command() ) );
			}
		}
	}

	/**
	 * @Then the JSON output should not contain items:
	 */
	public function theJsonOutputShouldNotContainItems(TableNode $table)
	{
		$items = $this->json_output();
		foreach ( $this->table_hash( $table ) as $unexpected ) {
			if ( null !== self::find_matching_item( $items, $unexpected ) ) {
				throw new Exception( 'A JSON item unexpectedly matches ' . json_encode( $unexpected ) . "\n" . self::describe( $this->last_command() ) );
			}
		}
	}

	/**
	 * @When I save the JSON item count as :var
	 */
	public function iSaveTheJsonItemCountAs($var)
	{
		$this->set_variable( $var, (string) count( $this->json_output() ) );
	}

	/**
	 * @When I save the :field of the JSON item where :key is :value as :var
	 */
	public function iSaveTheFieldOfTheJsonItemWhereIsAs($field, $key, $value, $var)
	{
		$item = self::find_matching_item( $this->json_output(), [ $key => $this->replace_variables( $value ) ] );
		if ( null === $item || ! array_key_exists( $field, $item ) ) {
			throw new Exception( "No JSON item with $key = $value and a '$field' field.\n" . self::describe( $this->last_command() ) );
		}
		$this->set_variable( $var, (string) $item[ $field ] );
	}

	/**
	 * Saves the value of a "Label: value" line of STDOUT.
	 *
	 * @When I save the :label value from STDOUT as :var
	 */
	public function iSaveTheValueFromStdoutAs($label, $var)
	{
		if ( ! preg_match( '/^\s*' . preg_quote( $label, '/' ) . ':[ \t]*(.*)$/m', $this->output( 'STDOUT' ), $m ) ) {
			throw new Exception( "No '$label:' line in STDOUT.\n" . self::describe( $this->last_command() ) );
		}
		$this->set_variable( $var, trim( $m[1] ) );
	}

	/* ------------------------------- Sites ------------------------------- */

	/**
	 * Creates the site if it is missing (and enables it if disabled), so scenarios can share it.
	 *
	 * @Given the :type site :site exists
	 */
	public function theSiteOfTypeExists($type, $site)
	{
		$this->theSiteOfTypeExistsWithFlags( $type, $site, '' );
	}

	/**
	 * Flags are only used when the site has to be created.
	 *
	 * @Given the :type site :site exists with flags :flags
	 */
	public function theSiteOfTypeExistsWithFlags($type, $site, $flags)
	{
		self::assert_prefixed_site( $site );
		$row = self::site_row( $site );
		if ( $row ) {
			if ( $row['site_type'] !== $type ) {
				throw new Exception( "Site $site exists with type {$row['site_type']}, expected $type." );
			}
			if ( ! (int) $row['site_enabled'] ) {
				self::ee_or_fail( 'site enable ' . escapeshellarg( $site ) );
			}

			return;
		}
		self::ee_or_fail( sprintf( 'site create %s --type=%s %s', escapeshellarg( $site ), escapeshellarg( $type ), $this->replace_variables( $flags ) ) );
	}

	/**
	 * @Given the site :site does not exist
	 */
	public function theSiteDoesNotExist($site)
	{
		self::assert_prefixed_site( $site );
		if ( self::site_row( $site ) ) {
			self::ee_or_fail( 'site delete ' . escapeshellarg( $site ) . ' --yes' );
		}
	}

	/**
	 * @Then the site :site should exist
	 */
	public function theSiteShouldExist($site)
	{
		if ( ! self::site_row( $site ) ) {
			throw new Exception( "Site $site has no row in the sites table." );
		}
	}

	/**
	 * @Then the site :site should not exist
	 */
	public function theSiteShouldNotExist($site)
	{
		if ( self::site_row( $site ) ) {
			throw new Exception( "Site $site still has a row in the sites table." );
		}
	}

	/* ----------------------------- Auth state ---------------------------- */

	/**
	 * Removes the site's rows and files directly, without the code under test.
	 *
	 * @Given the site :site has no http auth or whitelist
	 */
	public function theSiteHasNoHttpAuthOrWhitelist($site)
	{
		self::assert_prefixed_site( $site );
		if ( self::reset_test_sites_auth( $site ) ) {
			self::reload_proxy();
		}
	}

	/**
	 * Removes every global (`default`) auth row and htpasswd/default directly. Files of other sites
	 * with own users keep the global users until the scenario's state is restored.
	 *
	 * @Given there is no global http auth
	 */
	public function thereIsNoGlobalHttpAuth()
	{
		$this->require_destructive( __FUNCTION__ );
		$deleted = self::db()->exec( "DELETE FROM auth_users WHERE site_url = 'default'" );
		if ( $deleted || file_exists( self::htpasswd_path( 'default' ) ) ) {
			self::write_htpasswd( 'default', [] );
			self::reload_proxy();
		}
	}

	/**
	 * Read-only guard for non-destructive features: with global auth, their 401 checks would pass
	 * because of htpasswd/default and their unauthenticated 200 checks would fail.
	 *
	 * @Given global http auth is not enabled
	 */
	public function globalHttpAuthIsNotEnabled()
	{
		if ( self::db_rows( "SELECT id FROM auth_users WHERE site_url = 'default'" ) || file_exists( self::htpasswd_path( 'default' ) ) ) {
			throw new Exception( 'Global http auth is enabled (default rows or htpasswd/default); this feature needs it off.' );
		}
	}

	/**
	 * Removes the admin-tools auth row and file directly (ee has no command for it). Restored after the scenario.
	 *
	 * @Given there is no admin-tools http auth
	 */
	public function thereIsNoAdminToolsHttpAuth()
	{
		$this->require_destructive( __FUNCTION__ );
		self::db()->exec( "DELETE FROM auth_users WHERE site_url = 'default_admin_tools'" );
		self::write_htpasswd( 'default_admin_tools', [] );
		self::reload_proxy();
	}

	/**
	 * Keeps an existing admin-tools auth, otherwise creates one (user `easyengine`, like init_global_admin_tools_auth()).
	 *
	 * @Given the admin-tools http auth exists
	 */
	public function theAdminToolsHttpAuthExists()
	{
		$this->require_destructive( __FUNCTION__ );
		if ( self::db_rows( "SELECT id FROM auth_users WHERE site_url = 'default_admin_tools'" ) ) {
			return;
		}
		if ( self::db_rows( "SELECT id FROM auth_users WHERE site_url = 'default'" ) ) {
			throw new Exception( 'Global auth exists, so ee would not create an admin-tools auth. Use "there is no global http auth" first.' );
		}
		$row = [ 'site_url' => 'default_admin_tools', 'username' => 'easyengine', 'password' => 'bh-admin-' . bin2hex( random_bytes( 6 ) ) ];
		self::insert_row( 'auth_users', $row );
		self::write_htpasswd( 'default_admin_tools', [ $row ] );
		self::reload_proxy();
	}

	/**
	 * @Given no site has admin-tools or mailhog enabled
	 */
	public function noSiteHasAdminToolsOrMailhogEnabled()
	{
		$sites = self::db_rows( 'SELECT site_url FROM sites WHERE admin_tools = 1 OR mailhog_enabled = 1' );
		if ( $sites ) {
			throw new Exception( 'These sites have admin-tools or mailhog enabled: ' . implode( ', ', array_column( $sites, 'site_url' ) ) );
		}
	}

	/**
	 * @Given the frontend subnet is globally whitelisted
	 */
	public function theFrontendSubnetIsGloballyWhitelisted()
	{
		$subnet = self::frontend_subnet_ip();
		if ( ! self::db_rows( "SELECT id FROM auth_ips WHERE site_url = 'default' AND ip = ?", [ $subnet ] ) ) {
			throw new Exception( "The frontend subnet $subnet is not in the global whitelist; requests to 127.0.0.1 would not bypass auth." );
		}
	}

	/* ------------------------------ Database ----------------------------- */

	/**
	 * Vertical table: | column | value |.
	 *
	 * @Then the :table table should contain a row with:
	 */
	public function theTableShouldContainARowWith($table, TableNode $where)
	{
		$conditions = $this->rows_hash( $where );
		if ( 0 === self::count_rows( $table, $conditions ) ) {
			throw new Exception( "No row in $table with " . json_encode( $conditions ) . "\n" . self::dump_table( $table ) );
		}
	}

	/**
	 * @Then the :table table should not contain a row with:
	 */
	public function theTableShouldNotContainARowWith($table, TableNode $where)
	{
		$conditions = $this->rows_hash( $where );
		if ( 0 !== self::count_rows( $table, $conditions ) ) {
			throw new Exception( "Unexpected row in $table with " . json_encode( $conditions ) . "\n" . self::dump_table( $table ) );
		}
	}

	/**
	 * @Then the :table table should have :count rows with:
	 */
	public function theTableShouldHaveRowsWith($table, $count, TableNode $where)
	{
		$conditions = $this->rows_hash( $where );
		$actual     = self::count_rows( $table, $conditions );
		if ( (int) $count !== $actual ) {
			throw new Exception( "Expected $count rows in $table with " . json_encode( $conditions ) . ", found $actual.\n" . self::dump_table( $table ) );
		}
	}

	/* ------------------------------- Files ------------------------------- */

	/**
	 * @Then the file :path should exist
	 */
	public function theFileShouldExist($path)
	{
		$path = $this->replace_variables( $path );
		if ( ! file_exists( $path ) ) {
			throw new Exception( "File $path does not exist." );
		}
	}

	/**
	 * @Then the file :path should not exist
	 */
	public function theFileShouldNotExist($path)
	{
		$path = $this->replace_variables( $path );
		if ( file_exists( $path ) ) {
			throw new Exception( "File $path exists. Content:\n" . @file_get_contents( $path ) );
		}
	}

	/**
	 * @Then the file :path should contain:
	 */
	public function theFileShouldContain($path, PyStringNode $expected)
	{
		$path     = $this->replace_variables( $path );
		$expected = $this->replace_variables( $expected->getRaw() );
		$content  = self::read_file( $path );
		if ( false === strpos( $content, $expected ) ) {
			throw new Exception( "File $path does not contain:\n$expected\nContent:\n$content" );
		}
	}

	/**
	 * A missing file passes (it contains nothing).
	 *
	 * @Then the file :path should not contain:
	 */
	public function theFileShouldNotContain($path, PyStringNode $unexpected)
	{
		$path       = $this->replace_variables( $path );
		$unexpected = $this->replace_variables( $unexpected->getRaw() );
		if ( ! file_exists( $path ) ) {
			return;
		}
		$content = self::read_file( $path );
		if ( false !== strpos( $content, $unexpected ) ) {
			throw new Exception( "File $path unexpectedly contains:\n$unexpected\nContent:\n$content" );
		}
	}

	/**
	 * @Then the file :path should contain the line :line exactly :count times
	 */
	public function theFileShouldContainTheLineExactlyTimes($path, $line, $count)
	{
		$path    = $this->replace_variables( $path );
		$line    = $this->replace_variables( $line );
		$content = self::read_file( $path );
		$lines   = array_map( 'trim', preg_split( '/\R/', $content ) );
		$actual  = count( array_keys( $lines, $line, true ) );
		if ( (int) $count !== $actual ) {
			throw new Exception( "Expected the line '$line' $count times in $path, found $actual. Content:\n$content" );
		}
	}

	/* ------------------------------ htpasswd ----------------------------- */

	/**
	 * :name is relative to the nginx-proxy htpasswd dir (a site name, `default` or `default_admin_tools`).
	 *
	 * @Then the htpasswd file :name should contain user :user
	 */
	public function theHtpasswdFileShouldContainUser($name, $user)
	{
		$user  = $this->replace_variables( $user );
		$users = self::htpasswd_users( $this->replace_variables( $name ) );
		if ( ! in_array( $user, $users, true ) ) {
			throw new Exception( "htpasswd/$name has no user '$user'. Users: " . implode( ', ', $users ) );
		}
	}

	/**
	 * @Then the htpasswd file :name should not contain user :user
	 */
	public function theHtpasswdFileShouldNotContainUser($name, $user)
	{
		$user = $this->replace_variables( $user );
		$path = self::htpasswd_path( $this->replace_variables( $name ) );
		if ( ! file_exists( $path ) ) {
			return;
		}
		$users = self::htpasswd_users( $this->replace_variables( $name ) );
		if ( in_array( $user, $users, true ) ) {
			throw new Exception( "htpasswd/$name unexpectedly has user '$user'." );
		}
	}

	/**
	 * @Then the htpasswd file :name should accept user :user with password :pass
	 */
	public function theHtpasswdFileShouldAcceptUserWithPassword($name, $user, $pass)
	{
		$result = $this->verify_htpasswd( $name, $user, $pass );
		if ( 0 !== $result->return_code ) {
			throw new Exception( "htpasswd/$name rejects user '$user'.\n" . self::describe( $result ) );
		}
	}

	/**
	 * @Then the htpasswd file :name should reject user :user with password :pass
	 */
	public function theHtpasswdFileShouldRejectUserWithPassword($name, $user, $pass)
	{
		$result = $this->verify_htpasswd( $name, $user, $pass );
		if ( 0 === $result->return_code ) {
			throw new Exception( "htpasswd/$name accepts user '$user' with that password." );
		}
	}

	/* -------------------------------- HTTP ------------------------------- */

	/**
	 * @Then a GET request to :url with Host :host should return :code
	 */
	public function aGetRequestWithHostShouldReturn($url, $host, $code)
	{
		$this->assert_http_status( $url, $host, null, null, null, $code );
	}

	/**
	 * @Then a GET request to :url with Host :host as user :user with password :pass should return :code
	 */
	public function aGetRequestWithHostAsUserShouldReturn($url, $host, $user, $pass, $code)
	{
		$this->assert_http_status( $url, $host, null, $user, $pass, $code );
	}

	/**
	 * Resolves the URL's host to :ip (sets SNI and Host), certificate checks off.
	 *
	 * @Then a GET request to :url via :ip should return :code
	 */
	public function aGetRequestViaShouldReturn($url, $ip, $code)
	{
		$this->assert_http_status( $url, null, $ip, null, null, $code );
	}

	/**
	 * @Then a GET request to :url via :ip as user :user with password :pass should return :code
	 */
	public function aGetRequestViaAsUserShouldReturn($url, $ip, $user, $pass, $code)
	{
		$this->assert_http_status( $url, null, $ip, $user, $pass, $code );
	}

	/**
	 * @When I wait until a GET request to :url with Host :host returns :code up to :seconds seconds
	 */
	public function iWaitUntilAGetRequestReturns($url, $host, $code, $seconds)
	{
		$this->assert_http_status( $url, $host, null, null, null, $code, (int) $seconds );
	}

	/* ----------------------------- Migrations ---------------------------- */

	/** @var array Options changed by a scenario (key => old value or false), restored afterwards. */
	private $saved_options = [];

	/** @var array Migration rows deleted by a scenario (name => type), restored if still missing. */
	private $deleted_migrations = [];

	/** @var string|null /etc/hosts before "Docker Hub is unreachable". */
	private static $hosts_backup;

	/**
	 * Removes what auth-command releases before the wildcard work never wrote: the files of the
	 * alias domains and `_wildcard.*`, keeping `<site>` and `<site>_acl`.
	 *
	 * @Given the auth files of :site are as an older auth-command wrote them
	 */
	public function theAuthFilesAreAsAnOlderAuthCommandWroteThem($site)
	{
		self::assert_prefixed_site( $site );
		$rows    = self::db_rows( 'SELECT alias_domains FROM sites WHERE site_url = ?', [ $site ] );
		$aliases = empty( $rows[0]['alias_domains'] ) ? [] : explode( ',', $rows[0]['alias_domains'] );
		if ( count( $aliases ) < 2 ) {
			throw new Exception( "Site $site has no alias domains." );
		}
		foreach ( $aliases as $alias ) {
			$name = 0 === strpos( $alias, '*.' ) ? '_wildcard.' . substr( $alias, 2 ) : $alias;
			// alias_domains also lists the site itself, whose files older releases did write.
			if ( '' === $name || $site === $name || false !== strpbrk( $name, "/\\" ) ) {
				continue;
			}
			@unlink( EE_ROOT_DIR . '/services/nginx-proxy/htpasswd/' . $name );
			@unlink( EE_ROOT_DIR . '/services/nginx-proxy/vhost.d/' . $name . '_acl' );
			clearstatcache();
			if ( file_exists( EE_ROOT_DIR . '/services/nginx-proxy/htpasswd/' . $name ) ) {
				throw new Exception( "Could not remove htpasswd/$name." );
			}
		}
		self::reload_proxy();
	}

	/**
	 * Stores a whitelist entry unchecked, as auth-command before EasyEngine/auth-command#59 did, and writes the site's _acl file with it
	 * the same way. No reload: nginx would reject the file.
	 *
	 * @Given the whitelist entry :ip of :site was stored unchecked by an older auth-command
	 */
	public function theWhitelistEntryWasStoredUnchecked($ip, $site)
	{
		self::assert_prefixed_site( $site );
		self::insert_row( 'auth_ips', [ 'site_url' => $site, 'ip' => $ip ] );
		// Global entries first, then the site's own, like get_site_whitelist_ips().
		$rows    = self::db_rows( "SELECT ip FROM auth_ips WHERE site_url IN ('default', ?) ORDER BY site_url = ?, id", [ $site, $site ] );
		$content = 'satisfy any;' . PHP_EOL;
		foreach ( $rows as $row ) {
			$content .= 'allow ' . $row['ip'] . ';' . PHP_EOL;
		}
		if ( false === file_put_contents( EE_ROOT_DIR . '/services/nginx-proxy/vhost.d/' . $site . '_acl', $content . 'deny all;' ) ) {
			throw new Exception( "Could not write the _acl file of $site." );
		}
	}

	/**
	 * @Given the container migration :name is not recorded
	 */
	public function theContainerMigrationIsNotRecorded($name)
	{
		if ( ! array_key_exists( $name, $this->deleted_migrations ) && self::db_rows( 'SELECT 1 FROM migrations WHERE migration = ?', [ $name ] ) ) {
			$this->deleted_migrations[ $name ] = 'container';
		}
		self::db()->prepare( 'DELETE FROM migrations WHERE migration = ?' )->execute( [ $name ] );
	}

	/**
	 * @Given the EasyEngine option :key is set to :value
	 */
	public function theEasyEngineOptionIsSetTo($key, $value)
	{
		if ( ! array_key_exists( $key, $this->saved_options ) ) {
			$this->saved_options[ $key ] = self::option( $key );
		}
		self::set_option( $key, $this->replace_variables( $value ) );
	}

	/**
	 * @Then the EasyEngine option :key should be :value
	 */
	public function theEasyEngineOptionShouldBe($key, $value)
	{
		$actual = self::option( $key );
		if ( $this->replace_variables( $value ) !== $actual ) {
			throw new Exception( "Expected option '$key' to be '$value', got " . var_export( $actual, true ) );
		}
	}

	/**
	 * @Given Docker Hub is unreachable
	 */
	public function dockerHubIsUnreachable()
	{
		if ( null === self::$hosts_backup ) {
			self::$hosts_backup = file_get_contents( '/etc/hosts' );
			register_shutdown_function( [ self::class, 'restoreHosts' ] );
		}
		file_put_contents( '/etc/hosts', self::$hosts_backup . "\n127.0.0.1 registry-1.docker.io auth.docker.io index.docker.io\n" );
		// dockerd's Go resolver re-reads /etc/hosts at most every 5 s.
		sleep( 6 );
		$check = EE::launch( 'timeout 60 docker pull -q busybox:1.36', false, true );
		if ( 0 === $check->return_code ) {
			EE::launch( 'docker rmi busybox:1.36', false, true );
			self::restoreHosts();
			throw new Exception( 'Blocking Docker Hub in /etc/hosts had no effect (a registry mirror?).' );
		}
	}

	/**
	 * @When Docker Hub is reachable again
	 */
	public function dockerHubIsReachableAgain()
	{
		self::restoreHosts();
		sleep( 6 );
	}

	public static function restoreHosts()
	{
		if ( null !== self::$hosts_backup ) {
			file_put_contents( '/etc/hosts', self::$hosts_backup );
			self::$hosts_backup = null;
		}
	}

	/**
	 * @AfterScenario
	 */
	public function restoreMigrationState(AfterScenarioScope $scope)
	{
		self::restoreHosts();
		foreach ( $this->saved_options as $key => $value ) {
			if ( false === $value ) {
				self::db()->prepare( 'DELETE FROM options WHERE "key" = ?' )->execute( [ $key ] );
			} else {
				self::set_option( $key, $value );
			}
		}
		foreach ( $this->deleted_migrations as $name => $type ) {
			if ( ! self::db_rows( 'SELECT 1 FROM migrations WHERE migration = ?', [ $name ] ) ) {
				self::db()->prepare( 'INSERT INTO migrations (migration, type, timestamp) VALUES (?, ?, ?)' )->execute( [ $name, $type, date( 'Y-m-d H:i:s' ) ] );
			}
		}
		$this->saved_options      = [];
		$this->deleted_migrations = [];
	}

	private static function option($key)
	{
		$rows = self::db_rows( 'SELECT value FROM options WHERE "key" = ?', [ $key ] );

		return $rows ? $rows[0]['value'] : false;
	}

	private static function set_option($key, $value)
	{
		if ( false === self::option( $key ) ) {
			self::db()->prepare( 'INSERT INTO options ("key", value) VALUES (?, ?)' )->execute( [ $key, $value ] );
		} else {
			self::db()->prepare( 'UPDATE options SET value = ? WHERE "key" = ?' )->execute( [ $value, $key ] );
		}
	}

	/* ------------------------------- Docker ------------------------------ */

	/**
	 * @Then the docker container :name should be running
	 */
	public function theDockerContainerShouldBeRunning($name)
	{
		$result = EE::launch( "docker inspect -f '{{.State.Running}}' " . escapeshellarg( $this->replace_variables( $name ) ), false, true );
		if ( 'true' !== trim( $result->stdout ) ) {
			throw new Exception( "Container $name is not running.\n" . self::describe( $result ) );
		}
	}

	/* ------------------------------ Helpers ------------------------------ */

	private function last_command()
	{
		if ( empty( $this->commands ) ) {
			throw new Exception( 'No command has been run in this scenario.' );
		}

		return end( $this->commands );
	}

	private static function describe($result)
	{
		return sprintf(
			"Command: %s\nExit code: %s\nSTDOUT:\n%s\nSTDERR:\n%s",
			self::redact( $result->command ),
			$result->return_code,
			self::redact( self::strip_colors( $result->stdout ) ),
			self::redact( self::strip_colors( $result->stderr ) )
		);
	}

	/**
	 * Passwords of auth rows outside this suite's sites (global, admin-tools, other sites) are real and stay
	 * valid after the restore, so failure reports mask every one seen during the run.
	 */
	private static function remember_passwords(?array $rows = null)
	{
		if ( null === $rows ) {
			// Opening a missing SQLite file would create an empty EE database.
			if ( ! is_file( EE_ROOT_DIR . '/db/ee.sqlite' ) ) {
				return;
			}
			try {
				$rows = self::db_rows( 'SELECT password FROM auth_users WHERE site_url NOT LIKE ?', [ self::SITE_PREFIX . '%' ] );
			} catch ( \Throwable $e ) {
				return;
			}
		}
		foreach ( $rows as $row ) {
			if ( isset( $row['password'] ) && '' !== (string) $row['password'] ) {
				self::$known_passwords[ 'p' . $row['password'] ] = (string) $row['password'];
			}
		}
	}

	/**
	 * For EE::launch()'s obfuscation: `ee auth list` prints these in formats core's own masking doesn't cover.
	 */
	private static function secret_values()
	{
		self::remember_passwords();

		return array_values( self::$known_passwords );
	}

	private static function redact($text)
	{
		self::remember_passwords();
		$map = [];
		foreach ( self::$known_passwords as $password ) {
			$map[ $password ] = '******';
			// As it appears inside JSON output.
			$map[ substr( (string) json_encode( $password ), 1, -1 ) ] = '******';
		}
		unset( $map[''] );

		return $map ? strtr( (string) $text, $map ) : (string) $text;
	}

	private static function strip_colors($text)
	{
		return preg_replace( '/\e\[[0-9;]*m/', '', (string) $text );
	}

	private function output($stream)
	{
		$result = $this->last_command();

		return self::strip_colors( 'STDOUT' === $stream ? $result->stdout : $result->stderr );
	}

	private function json_output()
	{
		$data = json_decode( $this->output( 'STDOUT' ), true );
		if ( ! is_array( $data ) ) {
			throw new Exception( "STDOUT is not a JSON array/object.\n" . self::describe( $this->last_command() ) );
		}

		return $data;
	}

	private static function find_matching_item(array $items, array $expected)
	{
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			foreach ( $expected as $key => $value ) {
				if ( ! array_key_exists( $key, $item ) || (string) $item[ $key ] !== (string) $value ) {
					continue 2;
				}
			}

			return $item;
		}

		return null;
	}

	private function set_variable($name, $value)
	{
		if ( ! preg_match( '/^[A-Z][A-Z0-9_]*$/', $name ) ) {
			throw new Exception( "Variable names must be UPPER_SNAKE_CASE, got '$name'." );
		}
		$this->variables[ $name ] = $value;
	}

	private function replace_variables($text)
	{
		return preg_replace_callback(
			'/\{([A-Z][A-Z0-9_]*)\}/',
			function ( $m ) {
				$value = $this->variable( $m[1] );

				return null === $value ? $m[0] : $value;
			},
			(string) $text
		);
	}

	private function variable($name)
	{
		if ( array_key_exists( $name, $this->variables ) ) {
			return $this->variables[ $name ];
		}
		switch ( $name ) {
			case 'PUBLIC_IP':
				$value = self::public_ip();
				break;
			case 'EE_ROOT_DIR':
				$value = EE_ROOT_DIR;
				break;
			case 'EE_BIN':
				$value = self::ee_bin();
				break;
			case 'FRONTEND_SUBNET_IP':
				$value = self::frontend_subnet_ip();
				break;
			default:
				return null;
		}
		$this->variables[ $name ] = $value;

		return $value;
	}

	private function table_hash(TableNode $table)
	{
		$rows = [];
		foreach ( $table->getHash() as $row ) {
			$rows[] = array_map( [ $this, 'replace_variables' ], $row );
		}

		return $rows;
	}

	private function rows_hash(TableNode $table)
	{
		return array_map( [ $this, 'replace_variables' ], $table->getRowsHash() );
	}

	private function require_destructive($step)
	{
		if ( ! in_array( 'destructive', $this->scenario_tags, true ) ) {
			throw new Exception( "Step '$step' changes global state; tag the scenario or feature @destructive so it is restored afterwards." );
		}
	}

	private static function public_ip()
	{
		$ip = getenv( 'BEHAT_PUBLIC_IP' );
		if ( ! $ip ) {
			$ip = trim( (string) shell_exec( "hostname -I | awk '{print $1}'" ) );
		}
		// IPv4 only: an IPv6 address would need brackets in the URLs.
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			throw new Exception( "Could not determine the public IPv4 address (got '$ip'); set BEHAT_PUBLIC_IP." );
		}

		return $ip;
	}

	private static function ee_bin()
	{
		return realpath( EE_ROOT ) . '/bin/ee';
	}

	private static function ee($args)
	{
		return EE::launch( escapeshellarg( self::ee_bin() ) . ' ' . $args, false, true, self::secret_values() );
	}

	private static function ee_or_fail($args)
	{
		$result = self::ee( $args );
		if ( 0 !== $result->return_code ) {
			throw new Exception( "Setup command failed.\n" . self::describe( $result ) );
		}

		return $result;
	}

	private static function assert_prefixed_site($site)
	{
		if ( 0 !== strpos( $site, self::SITE_PREFIX ) ) {
			throw new Exception( "Test sites must start with '" . self::SITE_PREFIX . "', got '$site'." );
		}
	}

	private static function db()
	{
		static $pdo = null;
		if ( null === $pdo ) {
			$pdo = new PDO( 'sqlite:' . EE_ROOT_DIR . '/db/ee.sqlite' );
			$pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
			$pdo->setAttribute( PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC );
			$pdo->setAttribute( PDO::ATTR_TIMEOUT, 30 );
		}

		return $pdo;
	}

	private static function db_rows($sql, array $params = [])
	{
		$statement = self::db()->prepare( $sql );
		$statement->execute( $params );

		return $statement->fetchAll();
	}

	private static function assert_identifier($name)
	{
		if ( ! preg_match( '/^[a-z_][a-z0-9_]*$/', $name ) ) {
			throw new Exception( "Invalid table or column name '$name'." );
		}
	}

	private static function count_rows($table, array $conditions)
	{
		self::assert_identifier( $table );
		$where  = [];
		$params = [];
		foreach ( $conditions as $column => $value ) {
			self::assert_identifier( $column );
			$where[]  = "$column = ?";
			$params[] = $value;
		}
		$sql = "SELECT COUNT(*) AS n FROM $table" . ( $where ? ' WHERE ' . implode( ' AND ', $where ) : '' );

		return (int) self::db_rows( $sql, $params )[0]['n'];
	}

	private static function dump_table($table)
	{
		self::assert_identifier( $table );
		$rows = self::db_rows( "SELECT * FROM $table" );
		foreach ( $rows as &$row ) {
			if ( isset( $row['password'] ) && ( ! isset( $row['site_url'] ) || 0 !== strpos( (string) $row['site_url'], self::SITE_PREFIX ) ) ) {
				$row['password'] = '******';
			}
		}
		unset( $row );

		return "Rows in $table:\n" . implode( "\n", array_map( 'json_encode', $rows ) );
	}

	private static function insert_row($table, array $row)
	{
		self::assert_identifier( $table );
		array_map( [ __CLASS__, 'assert_identifier' ], array_keys( $row ) );
		$sql = sprintf( 'INSERT INTO %s (%s) VALUES (%s)', $table, implode( ', ', array_keys( $row ) ), implode( ', ', array_fill( 0, count( $row ), '?' ) ) );
		self::db()->prepare( $sql )->execute( array_values( $row ) );
	}

	private static function site_row($site)
	{
		$rows = self::db_rows( 'SELECT site_url, site_type, site_enabled FROM sites WHERE site_url = ?', [ $site ] );

		return $rows ? $rows[0] : null;
	}

	private static function frontend_subnet_ip()
	{
		$rows = self::db_rows( "SELECT value FROM options WHERE \"key\" = 'frontend_subnet_ip'" );
		if ( ! $rows ) {
			throw new Exception( 'The frontend_subnet_ip option is not set.' );
		}

		return $rows[0]['value'];
	}

	private static function read_file($path)
	{
		if ( ! file_exists( $path ) ) {
			throw new Exception( "File $path does not exist." );
		}

		return (string) file_get_contents( $path );
	}

	private static function htpasswd_path($name)
	{
		return EE_ROOT_DIR . '/services/nginx-proxy/htpasswd/' . $name;
	}

	private static function acl_path($name)
	{
		return EE_ROOT_DIR . '/services/nginx-proxy/vhost.d/' . $name . '_acl';
	}

	private static function htpasswd_users($name)
	{
		$users = [];
		foreach ( preg_split( '/\R/', self::read_file( self::htpasswd_path( $name ) ) ) as $line ) {
			if ( false !== strpos( $line, ':' ) ) {
				$users[] = substr( $line, 0, strpos( $line, ':' ) );
			}
		}

		return $users;
	}

	/**
	 * `htpasswd -v` only reads the file. Runs inside the proxy, which owns the htpasswd volume.
	 */
	private function verify_htpasswd($name, $user, $pass)
	{
		$name = $this->replace_variables( $name );
		$pass = $this->replace_variables( $pass );
		self::read_file( self::htpasswd_path( $name ) );

		// The admin-tools password is the machine's real one: keep it out of ee.log and failure reports.
		$secrets = [ escapeshellarg( $pass ), $pass ];
		$result  = EE::launch(
			sprintf(
				'docker exec %s htpasswd -vb %s %s %s',
				escapeshellarg( EE_PROXY_TYPE ),
				escapeshellarg( '/etc/nginx/htpasswd/' . $name ),
				escapeshellarg( $this->replace_variables( $user ) ),
				escapeshellarg( $pass )
			),
			false,
			true,
			$secrets
		);
		$result->command = self::mask( $result->command, $secrets );

		return $result;
	}

	private static function mask($text, array $secrets)
	{
		foreach ( $secrets as $secret ) {
			if ( '' !== (string) $secret ) {
				$text = str_replace( (string) $secret, '******', $text );
			}
		}

		return $text;
	}

	private function assert_http_status($url, $host, $resolve_ip, $user, $pass, $code, $timeout = self::HTTP_SETTLE_SECONDS)
	{
		$url  = $this->replace_variables( $url );
		$args = [ '-sS', '-k', '--max-time', '15', '-o', '-', '-w', '\n%{http_code}' ];
		if ( null !== $host ) {
			$args[] = '-H';
			$args[] = 'Host: ' . $this->replace_variables( $host );
		}
		if ( null !== $resolve_ip ) {
			$parts  = parse_url( $url );
			$port   = isset( $parts['port'] ) ? $parts['port'] : ( 'https' === $parts['scheme'] ? 443 : 80 );
			$args[] = '--resolve';
			$args[] = $parts['host'] . ':' . $port . ':' . $this->replace_variables( $resolve_ip );
		}
		if ( null !== $user ) {
			$args[] = '-u';
			$args[] = $this->replace_variables( $user ) . ':' . $this->replace_variables( $pass );
		}
		$args[]  = $url;
		$command = 'curl ' . implode( ' ', array_map( 'escapeshellarg', $args ) );
		$secrets = [];
		if ( null !== $user ) {
			$credential = $this->replace_variables( $user ) . ':' . $this->replace_variables( $pass );
			$secrets    = [ escapeshellarg( $credential ), $credential, $this->replace_variables( $pass ) ];
		}

		$deadline = time() + $timeout;
		do {
			$result = EE::launch( $command, false, true, $secrets );
			$lines  = explode( "\n", rtrim( $result->stdout ) );
			$actual = array_pop( $lines );
			if ( (string) $actual === (string) $code ) {
				return;
			}
			if ( time() >= $deadline ) {
				break;
			}
			sleep( 1 );
		} while ( true );

		throw new Exception(
			sprintf(
				"GET %s%s%s returned %s, expected %s (retried for %ds).\nBody (first 500 bytes):\n%s\ncurl STDERR: %s",
				$url,
				null !== $host ? " (Host: $host)" : '',
				null !== $user ? " as $user" : '',
				$actual,
				$code,
				$timeout,
				substr( implode( "\n", $lines ), 0, 500 ),
				$result->stderr
			)
		);
	}

	/* ------------------------ Auth state management ---------------------- */

	private static function htpasswd_dir()
	{
		return EE_ROOT_DIR . '/services/nginx-proxy/htpasswd';
	}

	private static function vhost_dir()
	{
		return EE_ROOT_DIR . '/services/nginx-proxy/vhost.d';
	}

	/**
	 * Whether a proxy file belongs to one of this suite's sites (also `_wildcard.` and `.tmp` variants).
	 */
	private static function is_test_file_name($name)
	{
		$name = preg_replace( '/^\.?(_wildcard\.)?/', '', $name );

		return 0 === strpos( $name, self::SITE_PREFIX );
	}

	private static function is_plain_file_name($name)
	{
		return is_string( $name ) && '' !== $name && '.' !== $name && '..' !== $name && false === strpbrk( $name, "/\\\0" );
	}

	/**
	 * Regular files auth-command manages: everything in htpasswd/ and vhost.d/*_acl, as [kind => [name => path]].
	 */
	private static function auth_files($test_sites)
	{
		$files = [ 'htpasswd' => [], 'acl' => [] ];
		foreach ( [ 'htpasswd' => self::htpasswd_dir(), 'acl' => self::vhost_dir() ] as $kind => $dir ) {
			foreach ( is_dir( $dir ) ? scandir( $dir ) : [] as $name ) {
				$path = "$dir/$name";
				if ( ! self::is_plain_file_name( $name ) || is_link( $path ) || ! is_file( $path ) ) {
					continue;
				}
				if ( 'acl' === $kind && '_acl' !== substr( $name, -4 ) ) {
					continue;
				}
				if ( self::is_test_file_name( $name ) === $test_sites ) {
					$files[ $kind ][ $name ] = $path;
				}
			}
		}

		return $files;
	}

	/**
	 * Removes the auth rows and files of this suite's sites (all of them, or one), directly.
	 *
	 * @return bool Whether anything was removed.
	 */
	private static function reset_test_sites_auth($site = null)
	{
		$changed = false;
		foreach ( [ 'auth_users', 'auth_ips' ] as $table ) {
			$statement = self::db()->prepare( "DELETE FROM $table WHERE site_url " . ( null === $site ? 'LIKE ?' : '= ?' ) );
			$statement->execute( [ null === $site ? self::SITE_PREFIX . '%' : $site ] );
			$changed = $statement->rowCount() > 0 || $changed;
		}
		foreach ( self::auth_files( true ) as $kind => $files ) {
			foreach ( $files as $name => $path ) {
				$base = preg_replace( [ '/^\.?(_wildcard\.)?/', '/(_acl)?(\.tmp)?$/' ], '', $name );
				if ( null === $site || $base === $site ) {
					if ( ! unlink( $path ) ) {
						throw new Exception( "Could not remove $path." );
					}
					$changed = true;
				}
			}
		}

		return $changed;
	}

	private static function cleanup_prefixed_sites()
	{
		$like = self::SITE_PREFIX . '%';
		foreach ( self::db_rows( 'SELECT site_url FROM sites WHERE site_url LIKE ?', [ $like ] ) as $row ) {
			self::ee( 'site delete ' . escapeshellarg( $row['site_url'] ) . ' --yes' );
		}

		// Leftovers of older versions (site delete kept the _acl file before EasyEngine/auth-command#57) and of failed deletes.
		if ( self::reset_test_sites_auth() ) {
			self::reload_proxy();
		}
	}

	/**
	 * Everything auth-command writes outside this suite's sites: all auth_users/auth_ips rows (the
	 * global, admin-tools and other sites' rows) and the bytes and modes of their htpasswd/_acl files.
	 */
	private static function capture_auth_state()
	{
		$like  = self::SITE_PREFIX . '%';
		$state = [
			'time'       => date( 'c' ),
			'auth_users' => self::db_rows( 'SELECT id, site_url, username, password FROM auth_users WHERE site_url NOT LIKE ? ORDER BY id', [ $like ] ),
			'auth_ips'   => self::db_rows( 'SELECT id, site_url, ip FROM auth_ips WHERE site_url NOT LIKE ? ORDER BY id', [ $like ] ),
			'files'      => [ 'htpasswd' => [], 'acl' => [] ],
		];
		self::remember_passwords( $state['auth_users'] );
		foreach ( self::auth_files( false ) as $kind => $files ) {
			foreach ( $files as $name => $path ) {
				$content = file_get_contents( $path );
				if ( false === $content ) {
					throw new Exception( "Could not read $path for the auth state snapshot." );
				}
				$state['files'][ $kind ][ $name ] = [ 'mode' => fileperms( $path ) & 0777, 'content' => base64_encode( $content ) ];
			}
		}

		return $state;
	}

	/**
	 * Puts the rows and files back byte for byte (removing the ones created since), removes this
	 * suite's sites' auth, and reloads the proxy. Uses PDO and the filesystem only.
	 */
	private static function restore_auth_state(array $snapshot)
	{
		$pdo = self::db();
		$pdo->beginTransaction();
		try {
			$pdo->exec( 'DELETE FROM auth_users' );
			$pdo->exec( 'DELETE FROM auth_ips' );
			foreach ( $snapshot['auth_users'] as $row ) {
				self::insert_row( 'auth_users', $row );
			}
			foreach ( $snapshot['auth_ips'] as $row ) {
				self::insert_row( 'auth_ips', $row );
			}
			$pdo->commit();
		} catch ( Exception $e ) {
			$pdo->rollBack();
			throw $e;
		}

		self::reset_test_sites_auth();
		$dirs = [ 'htpasswd' => self::htpasswd_dir(), 'acl' => self::vhost_dir() ];
		foreach ( self::auth_files( false ) as $kind => $files ) {
			foreach ( array_diff_key( $files, $snapshot['files'][ $kind ] ) as $path ) {
				if ( ! unlink( $path ) ) {
					throw new Exception( "Could not remove $path." );
				}
			}
		}
		foreach ( $snapshot['files'] as $kind => $files ) {
			foreach ( $files as $name => $file ) {
				$path    = $dirs[ $kind ] . '/' . $name;
				$content = base64_decode( $file['content'], true );
				if ( is_link( $path ) || ! is_file( $path ) || file_get_contents( $path ) !== $content ) {
					// Written next to the target and renamed, so the proxy never reads a partial file.
					$tmp = $dirs[ $kind ] . '/.behat-restore.tmp';
					if ( false === file_put_contents( $tmp, $content ) || ! rename( $tmp, $path ) ) {
						throw new Exception( "Could not restore $path." );
					}
				}
				chmod( $path, $file['mode'] );
			}
		}

		self::reload_proxy();
		if ( file_exists( self::snapshot_file() ) && ! unlink( self::snapshot_file() ) ) {
			throw new Exception( 'Could not remove ' . self::snapshot_file() . '.' );
		}
	}

	/**
	 * Next to the data it protects, so it survives a killed run (and isn't in world-writable /tmp).
	 */
	private static function snapshot_file()
	{
		return EE_ROOT_DIR . '/.behat-' . rtrim( self::SITE_PREFIX, '-' ) . '-auth-snapshot.json';
	}

	private static function write_pending_snapshot(array $snapshot)
	{
		$file = self::snapshot_file();
		$tmp  = "$file.tmp";
		$old  = umask( 0077 );
		$ok   = false !== file_put_contents( $tmp, json_encode( $snapshot ) ) && rename( $tmp, $file );
		umask( $old );
		if ( ! $ok ) {
			throw new Exception( "Could not save the auth state snapshot to $file." );
		}
	}

	private static function read_pending_snapshot()
	{
		$file = self::snapshot_file();
		if ( ! file_exists( $file ) ) {
			return null;
		}
		$snapshot = json_decode( (string) file_get_contents( $file ), true );
		$valid    = is_array( $snapshot ) && isset( $snapshot['time'], $snapshot['auth_users'], $snapshot['auth_ips'], $snapshot['files']['htpasswd'], $snapshot['files']['acl'] );
		foreach ( $valid ? $snapshot['files'] : [] as $kind => $files ) {
			foreach ( $files as $name => $entry ) {
				$valid = $valid && self::is_plain_file_name( $name ) && ! self::is_test_file_name( $name ) && ( 'htpasswd' === $kind || '_acl' === substr( $name, -4 ) ) && isset( $entry['mode'], $entry['content'] );
			}
		}
		if ( ! $valid ) {
			throw new Exception( "$file is not a valid auth state snapshot; inspect it and remove it by hand." );
		}

		return $snapshot;
	}

	/**
	 * Scenarios that change global auth must be @destructive, or nothing would restore it.
	 */
	private static function assert_no_global_auth_commands(BeforeScenarioScope $scope)
	{
		$steps      = $scope->getScenario()->getSteps();
		$background = $scope->getFeature()->getBackground();
		if ( null !== $background ) {
			$steps = array_merge( $background->getSteps(), $steps );
		}
		foreach ( $steps as $step ) {
			if ( preg_match( '/\bauth\s+(create|update|delete)\s+global\b/', $step->getText() ) ) {
				throw new Exception( "Step '{$step->getText()}' changes global auth; tag the scenario or feature @destructive." );
			}
		}
	}

	private static function write_htpasswd($name, array $rows)
	{
		$path = self::htpasswd_path( $name );
		if ( empty( $rows ) ) {
			if ( file_exists( $path ) ) {
				unlink( $path );
			}

			return;
		}
		foreach ( array_values( $rows ) as $i => $row ) {
			$result = EE::launch(
				sprintf(
					'docker exec %s htpasswd -%s %s %s %s',
					escapeshellarg( EE_PROXY_TYPE ),
					0 === $i ? 'bc' : 'b',
					escapeshellarg( '/etc/nginx/htpasswd/' . $name ),
					escapeshellarg( $row['username'] ),
					escapeshellarg( $row['password'] )
				),
				false,
				true,
				[ $row['password'] ]
			);
			if ( 0 !== $result->return_code ) {
				throw new Exception( "Could not write htpasswd/$name.\n" . self::describe( $result ) );
			}
		}
	}

	/**
	 * Same sequence as site-command's reload_global_nginx_proxy(), but every step must succeed.
	 */
	private static function reload_proxy()
	{
		$proxy = escapeshellarg( EE_PROXY_TYPE );
		$steps = [
			"docker exec $proxy sh -c '/app/docker-entrypoint.sh /usr/local/bin/docker-gen /app/nginx.tmpl /etc/nginx/conf.d/default.conf'",
			"docker exec $proxy nginx -t",
			"docker exec $proxy nginx -s reload",
		];
		// The proxy's own docker-gen watcher can be rewriting default.conf at the same time, so nginx -t may
		// read a truncated file; retry the whole sequence a few times before giving up.
		for ( $attempt = 1; ; $attempt++ ) {
			foreach ( $steps as $command ) {
				$result = EE::launch( $command, false, true );
				if ( 0 !== $result->return_code ) {
					break;
				}
			}
			if ( 0 === $result->return_code ) {
				return;
			}
			if ( $attempt >= 5 ) {
				throw new Exception( "Reloading the proxy after an auth state change failed.\n" . self::describe( $result ) );
			}
			sleep( 2 );
		}
	}
}
