<?php
/**
 * @author Joas Schilling <coding@schilljs.com>
 * @author Robin Appelman <icewind@owncloud.com>
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 * @author Vincent Petry <pvince81@owncloud.com>
 *
 * @copyright Copyright (c) 2017, ownCloud GmbH
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OCA\DAV\Tests\unit\Connector\Sabre;

use OC\Files\FileInfo;
use OC\Files\Storage\Local;
use OC\Files\View;
use OCA\DAV\Connector\Sabre\Exception\FileLocked;
use OCA\DAV\Connector\Sabre\File;
use OCP\Constants;
use OCP\Encryption\Exceptions\GenericEncryptionException;
use OCP\Files\EntityTooLargeException;
use OCP\Files\ForbiddenException;
use OCP\Files\InvalidContentException;
use OCP\Files\InvalidPathException;
use OCP\Files\LockNotAcquiredException;
use OCP\Files\NotPermittedException;
use OCP\Files\Storage;
use OCP\Files\StorageNotAvailableException;
use OCP\Lock\LockedException;
use OCP\Util;
use Sabre\DAV\Exception;
use Sabre\DAV\Exception\BadRequest;
use Test\HookHelper;
use OC\Files\Filesystem;
use OCP\Lock\ILockingProvider;
use Test\TestCase;

/**
 * Class File
 *
 * @group DB
 *
 * @package OCA\DAV\Tests\unit\Connector\Sabre
 */
class FileTest extends TestCase {

	/**
	 * @var string
	 */
	private $user;

	/** @var IConfig | \PHPUnit_Framework_MockObject_MockObject */
	protected $config;

	public function setUp() {
		parent::setUp();
		unset($_SERVER['HTTP_OC_CHUNKED']);
		unset($_SERVER['CONTENT_LENGTH']);
		unset($_SERVER['REQUEST_METHOD']);

		\OC_Hook::clear();

		$this->user = $this->getUniqueID('user_');
		$userManager = \OC::$server->getUserManager();
		$userManager->createUser($this->user, 'pass');

		$this->loginAsUser($this->user);
		
		$this->config = $this->getMockBuilder('\OCP\IConfig')->getMock();
	}

	public function tearDown() {
		$userManager = \OC::$server->getUserManager();
		if ($userManager->userExists($this->user)) {
			$userManager->get($this->user)->delete();
		}
		unset($_SERVER['HTTP_OC_CHUNKED']);

		parent::tearDown();
	}

	private function getMockStorage() {
		$storage = $this->createMock(Storage::class);
		$storage->expects($this->any())
			->method('getId')
			->will($this->returnValue('home::someuser'));
		return $storage;
	}

	/**
	 * @param string $string
	 */
	private function getStream($string) {
		$stream = fopen('php://temp', 'r+');
		fwrite($stream, $string);
		fseek($stream, 0);
		return $stream;
	}


	public function fopenFailuresProvider() {
		return [
			[
				// return false
				null,
				'\Sabre\Dav\Exception',
				false
			],
			[
				new NotPermittedException(),
				'Sabre\DAV\Exception\Forbidden'
			],
			[
				new EntityTooLargeException(),
				'OCA\DAV\Connector\Sabre\Exception\EntityTooLarge'
			],
			[
				new InvalidContentException(),
				'OCA\DAV\Connector\Sabre\Exception\UnsupportedMediaType'
			],
			[
				new InvalidPathException(),
				'Sabre\DAV\Exception\Forbidden'
			],
			[
				new ForbiddenException('', true),
				'OCA\DAV\Connector\Sabre\Exception\Forbidden'
			],
			[
				new LockNotAcquiredException('/test.txt', 1),
				'OCA\DAV\Connector\Sabre\Exception\FileLocked'
			],
			[
				new LockedException('/test.txt'),
				'OCA\DAV\Connector\Sabre\Exception\FileLocked'
			],
			[
				new GenericEncryptionException(),
				'Sabre\DAV\Exception\ServiceUnavailable'
			],
			[
				new StorageNotAvailableException(),
				'Sabre\DAV\Exception\ServiceUnavailable'
			],
			[
				new Exception('Generic sabre exception'),
				'Sabre\DAV\Exception',
				false
			],
			[
				new \Exception('Generic exception'),
				'Sabre\DAV\Exception'
			],
		];
	}

	/**
	 * @dataProvider fopenFailuresProvider
	 */
	public function testSimplePutFails($thrownException, $expectedException, $checkPreviousClass = true) {
		// setup
		$storage = $this->getMockBuilder(Local::class)
			->setMethods(['fopen'])
			->setConstructorArgs([['datadir' => \OC::$server->getTempManager()->getTemporaryFolder()]])
			->getMock();
		Filesystem::mount($storage, [], $this->user . '/');
		$view = $this->getMockBuilder(View::class)
			->setMethods(['getRelativePath', 'resolvePath'])
			->setConstructorArgs([])
			->getMock();
		$view->expects($this->atLeastOnce())
			->method('resolvePath')
			->will($this->returnCallback(
				function ($path) use ($storage) {
					return [$storage, $path];
				}
			));

		if ($thrownException !== null) {
			$storage->expects($this->once())
				->method('fopen')
				->will($this->throwException($thrownException));
		} else {
			$storage->expects($this->once())
				->method('fopen')
				->will($this->returnValue(false));
		}

		$view->expects($this->any())
			->method('getRelativePath')
			->will($this->returnArgument(0));

		$info = new FileInfo('/test.txt', $this->getMockStorage(), null, [
			'permissions' => Constants::PERMISSION_ALL
		], null);

		$file = new File($view, $info);

		// action
		$caughtException = null;
		try {
			$file->put('test data');
		} catch (\Exception $e) {
			$caughtException = $e;
		}

		$this->assertInstanceOf($expectedException, $caughtException);
		if ($checkPreviousClass) {
			$this->assertInstanceOf(get_class($thrownException), $caughtException->getPrevious());
		}

		$this->assertEmpty($this->listPartFiles($view, ''), 'No stray part files');
	}

	/**
	 * Test putting a file using chunking
	 *
	 * @dataProvider fopenFailuresProvider
	 */
	public function testChunkedPutFails($thrownException, $expectedException, $checkPreviousClass = false) {
		// setup
		$storage = $this->getMockBuilder(Local::class)
			->setMethods(['fopen'])
			->setConstructorArgs([['datadir' => \OC::$server->getTempManager()->getTemporaryFolder()]])
			->getMock();
		Filesystem::mount($storage, [], $this->user . '/');
		$view = $this->createMock(View::class, ['getRelativePath', 'resolvePath'], []);
		$view->expects($this->atLeastOnce())
			->method('resolvePath')
			->will($this->returnCallback(
				function ($path) use ($storage) {
					return [$storage, $path];
				}
			));

		if ($thrownException !== null) {
			$storage->expects($this->once())
				->method('fopen')
				->will($this->throwException($thrownException));
		} else {
			$storage->expects($this->once())
				->method('fopen')
				->will($this->returnValue(false));
		}

		$view->expects($this->any())
			->method('getRelativePath')
			->will($this->returnArgument(0));

		$_SERVER['HTTP_OC_CHUNKED'] = true;

		$info = new FileInfo('/test.txt-chunking-12345-2-0', $this->getMockStorage(), null, [
			'permissions' => Constants::PERMISSION_ALL
		], null);
		$file = new File($view, $info);

		// put first chunk
		$file->acquireLock(ILockingProvider::LOCK_SHARED);
		$this->assertNull($file->put('test data one'));
		$file->releaseLock(ILockingProvider::LOCK_SHARED);

		$info = new FileInfo('/test.txt-chunking-12345-2-1', $this->getMockStorage(), null, [
			'permissions' => Constants::PERMISSION_ALL
		], null);
		$file = new File($view, $info);

		// action
		$caughtException = null;
		try {
			// last chunk
			$file->acquireLock(ILockingProvider::LOCK_SHARED);
			$file->put('test data two');
			$file->releaseLock(ILockingProvider::LOCK_SHARED);
		} catch (\Exception $e) {
			$caughtException = $e;
		}

		$this->assertInstanceOf($expectedException, $caughtException);
		if ($checkPreviousClass) {
			$this->assertInstanceOf(get_class($thrownException), $caughtException->getPrevious());
		}

		$this->assertEmpty($this->listPartFiles($view, ''), 'No stray part files');
	}

	/**
	 * Simulate putting a file to the given path.
	 *
	 * @param string $path path to put the file into
	 * @param string $viewRoot root to use for the view
	 *
	 * @return null|string of the PUT operaiton which is usually the etag
	 */
	private function doPut($path, $viewRoot = null, \OC\AppFramework\Http\Request $request = null) {
		$view = Filesystem::getView();
		if (!is_null($viewRoot)) {
			$view = new View($viewRoot);
		} else {
			$viewRoot = '/' . $this->user . '/files';
		}

		$info = new FileInfo(
			$viewRoot . '/' . ltrim($path, '/'),
			$this->getMockStorage(),
			null,
			['permissions' => Constants::PERMISSION_ALL],
			null
		);

		$file = new File($view, $info, null, $request);

		// beforeMethod locks
		$view->lockFile($path, ILockingProvider::LOCK_SHARED);

		$result = $file->put($this->getStream('test data'));

		// afterMethod unlocks
		$view->unlockFile($path, ILockingProvider::LOCK_SHARED);

		return $result;
	}

	/**
	 * Test putting a single file
	 */
	public function testPutSingleFile() {
		$this->assertNotEmpty($this->doPut('/foo.txt'));
	}

	/**
	 * Determine if the underlying storage supports a negative mtime value
	 *
	 * @return boolean true if negative mtime is supported
	 */
	private function supportsNegativeMtime() {
		return (getenv("PRIMARY_STORAGE_CONFIG") !== "swift");
	}

	public function legalMtimeProvider() {
		return [
			"string" => [ 
					'HTTP_X_OC_MTIME' => "string",
					'expected result' => 0
			],
			"castable string (int)" => [
					'HTTP_X_OC_MTIME' => "34",
					'expected result' => 34
			],
			"castable string (float)" => [ 
					'HTTP_X_OC_MTIME' => "34.56",
					'expected result' => 34
			],
			"float" => [
					'HTTP_X_OC_MTIME' => 34.56,
					'expected result' => 34
			],
			"zero" => [
					'HTTP_X_OC_MTIME' => 0,
					'expected result' => 0
			],
			"zero string" => [
					'HTTP_X_OC_MTIME' => "0",
					'expected result' => 0
			],
			"negative zero string" => [
					'HTTP_X_OC_MTIME' => "-0",
					'expected result' => 0
			],
			"string starting with number following by char" => [
					'HTTP_X_OC_MTIME' => "2345asdf",
					'expected result' => 2345
			],
			"string castable hex int" => [
					'HTTP_X_OC_MTIME' => "0x45adf",
					'expected result' => 0
			],
			"string that looks like invalid hex int" => [
					'HTTP_X_OC_MTIME' => "0x123g",
					'expected result' => 0
			],
			"negative int" => [
					'HTTP_X_OC_MTIME' => -34,
					'expected result' => ($this->supportsNegativeMtime() ? -34 : 0)
			],
			"negative float" => [
					'HTTP_X_OC_MTIME' => -34.43,
					'expected result' => ($this->supportsNegativeMtime() ? -34 : 0)
			],
		];
	}

	/**
	 * Test putting a file with string Mtime
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @dataProvider legalMtimeProvider
	 */
	public function testPutSingleFileLegalMtime($requestMtime, $resultMtime) {
		$request = new \OC\AppFramework\Http\Request([
				'server' => [
						'HTTP_X_OC_MTIME' => $requestMtime,
				]
		], null, $this->config, null);
		$file = 'foo.txt';
		$this->doPut($file, null, $request);
		$this->assertEquals($resultMtime, $this->getFileInfos($file)['mtime']);
	}

	/**
	 * Test putting a file with string Mtime using chunking
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @dataProvider legalMtimeProvider
	 */
	public function testChunkedPutLegalMtime($requestMtime, $resultMtime) {
		$request = new \OC\AppFramework\Http\Request([
				'server' => [
						'HTTP_X_OC_MTIME' => $requestMtime,
				]
		], null, $this->config, null);
		
		$_SERVER['HTTP_OC_CHUNKED'] = true;
		$file = 'foo.txt';
		$this->doPut($file.'-chunking-12345-2-0', null, $request);
		$this->doPut($file.'-chunking-12345-2-1', null, $request);
		$this->assertEquals($resultMtime, $this->getFileInfos($file)['mtime']);
	}

	/**
	 * Test putting a file using chunking
	 */
	public function testChunkedPut() {
		$_SERVER['HTTP_OC_CHUNKED'] = true;
		$this->assertNull($this->doPut('/test.txt-chunking-12345-2-0'));
		$this->assertNotEmpty($this->doPut('/test.txt-chunking-12345-2-1'));
	}

	/**
	 * Test that putting a file triggers create hooks
	 */
	public function testPutSingleFileTriggersHooks() {
		HookHelper::setUpHooks();

		$this->assertNotEmpty($this->doPut('/foo.txt'));

		$this->assertCount(4, HookHelper::$hookCalls);
		$this->assertHookCall(
			HookHelper::$hookCalls[0],
			Filesystem::signal_create,
			'/foo.txt'
		);
		$this->assertHookCall(
			HookHelper::$hookCalls[1],
			Filesystem::signal_write,
			'/foo.txt'
		);
		$this->assertHookCall(
			HookHelper::$hookCalls[2],
			Filesystem::signal_post_create,
			'/foo.txt'
		);
		$this->assertHookCall(
			HookHelper::$hookCalls[3],
			Filesystem::signal_post_write,
			'/foo.txt'
		);
	}

	/**
	 * Test that putting a file triggers update hooks
	 */
	public function testPutOverwriteFileTriggersHooks() {
		$view = Filesystem::getView();
		$view->file_put_contents('/foo.txt', 'some content that will be replaced');

		HookHelper::setUpHooks();

		$this->assertNotEmpty($this->doPut('/foo.txt'));

		$this->assertCount(4, HookHelper::$hookCalls);
		$this->assertHookCall(
			HookHelper::$hookCalls[0],
			Filesystem::signal_update,
			'/foo.txt'
		);
		$this->assertHookCall(
			HookHelper::$hookCalls[1],
			Filesystem::signal_write,
			'/foo.txt'
		);
		$this->assertHookCall(
			HookHelper::$hookCalls[2],
			Filesystem::signal_post_update,
			'/foo.txt'
		);
		$this->assertHookCall(
			HookHelper::$hookCalls[3],
			Filesystem::signal_post_write,
			'/foo.txt'
		);
	}

	/**
	 * Test that putting a file triggers hooks with the correct path
	 * if the passed view was chrooted (can happen with public webdav
	 * where the root is the share root)
	 */
	public function testPutSingleFileTriggersHooksDifferentRoot() {
		$view = Filesystem::getView();
		$view->mkdir('noderoot');

		HookHelper::setUpHooks();

		// happens with public webdav where the view root is the share root
		$this->assertNotEmpty($this->doPut('/foo.txt', '/' . $this->user . '/files/noderoot'));

		$this->assertCount(4, HookHelper::$hookCalls);
		$this->assertHookCall(
			HookHelper::$hookCalls[0],
			Filesystem::signal_create,
			'/noderoot/foo.txt'
		);
		$this->assertHookCall(
			HookHelper::$hookCalls[1],
			Filesystem::signal_write,
			'/noderoot/foo.txt'
		);
		$this->assertHookCall(
			HookHelper::$hookCalls[2],
			Filesystem::signal_post_create,
			'/noderoot/foo.txt'
		);
		$this->assertHookCall(
			HookHelper::$hookCalls[3],
			Filesystem::signal_post_write,
			'/noderoot/foo.txt'
		);
	}

	/**
	 * Test that putting a file with chunks triggers create hooks
	 */
	public function testPutChunkedFileTriggersHooks() {
		HookHelper::setUpHooks();

		$_SERVER['HTTP_OC_CHUNKED'] = true;
		$this->assertNull($this->doPut('/foo.txt-chunking-12345-2-0'));
		$this->assertNotEmpty($this->doPut('/foo.txt-chunking-12345-2-1'));

		$this->assertCount(4, HookHelper::$hookCalls);
		$this->assertHookCall(
			HookHelper::$hookCalls[0],
			Filesystem::signal_create,
			'/foo.txt'
		);
		$this->assertHookCall(
			HookHelper::$hookCalls[1],
			Filesystem::signal_write,
			'/foo.txt'
		);
		$this->assertHookCall(
			HookHelper::$hookCalls[2],
			Filesystem::signal_post_create,
			'/foo.txt'
		);
		$this->assertHookCall(
			HookHelper::$hookCalls[3],
			Filesystem::signal_post_write,
			'/foo.txt'
		);
	}

	/**
	 * Test that putting a chunked file triggers update hooks
	 */
	public function testPutOverwriteChunkedFileTriggersHooks() {
		$view = Filesystem::getView();
		$view->file_put_contents('/foo.txt', 'some content that will be replaced');

		HookHelper::setUpHooks();

		$_SERVER['HTTP_OC_CHUNKED'] = true;
		$this->assertNull($this->doPut('/foo.txt-chunking-12345-2-0'));
		$this->assertNotEmpty($this->doPut('/foo.txt-chunking-12345-2-1'));

		$this->assertCount(4, HookHelper::$hookCalls);
		$this->assertHookCall(
			HookHelper::$hookCalls[0],
			Filesystem::signal_update,
			'/foo.txt'
		);
		$this->assertHookCall(
			HookHelper::$hookCalls[1],
			Filesystem::signal_write,
			'/foo.txt'
		);
		$this->assertHookCall(
			HookHelper::$hookCalls[2],
			Filesystem::signal_post_update,
			'/foo.txt'
		);
		$this->assertHookCall(
			HookHelper::$hookCalls[3],
			Filesystem::signal_post_write,
			'/foo.txt'
		);
	}

	/**
	 * Test put file with cancelled hook
	 */
	public function testPutSingleFileCancelPreHook() {
		Util::connectHook(
			Filesystem::CLASSNAME,
			Filesystem::signal_create,
			'\Test\HookHelper',
			'cancellingCallback'
		);

		// action
		$thrown = false;
		try {
			$this->doPut('/foo.txt');
		} catch (Exception $e) {
			$thrown = true;
		}

		// objectstore does not use partfiles -> no move after upload -> no exception
		if (getenv('RUN_OBJECTSTORE_TESTS')) {
			$this->assertFalse($thrown);
		} else {
			$this->assertTrue($thrown);
		}
		$this->assertEmpty($this->listPartFiles(), 'No stray part files');
	}

	/**
	 * Test exception when the uploaded size did not match
	 */
	public function testSimplePutFailsSizeCheck() {
		// setup
		$view = $this->getMockBuilder(View::class)
			->setMethods(['rename', 'getRelativePath', 'filesize'])
			->getMock();
		$view->expects($this->any())
			->method('rename')
			->withAnyParameters()
			->will($this->returnValue(false));
		$view->expects($this->any())
			->method('getRelativePath')
			->will($this->returnArgument(0));

		$view->expects($this->any())
			->method('filesize')
			->will($this->returnValue(123456));

		$_SERVER['CONTENT_LENGTH'] = 123456;
		$_SERVER['REQUEST_METHOD'] = 'PUT';

		$info = new FileInfo('/test.txt', $this->getMockStorage(), null, [
			'permissions' => Constants::PERMISSION_ALL
		], null);

		$file = new File($view, $info);

		// action
		$thrown = false;
		try {
			// beforeMethod locks
			$file->acquireLock(ILockingProvider::LOCK_SHARED);

			$file->put($this->getStream('test data'));

			// afterMethod unlocks
			$file->releaseLock(ILockingProvider::LOCK_SHARED);
		} catch (BadRequest $e) {
			$thrown = true;
		}

		$this->assertTrue($thrown);
		$this->assertEmpty($this->listPartFiles($view, ''), 'No stray part files');
	}

	/**
	 * Test exception during final rename in simple upload mode
	 */
	public function testSimplePutFailsMoveFromStorage() {
		$view = new View('/' . $this->user . '/files');

		// simulate situation where the target file is locked
		$view->lockFile('/test.txt', ILockingProvider::LOCK_EXCLUSIVE);

		$info = new FileInfo('/' . $this->user . '/files/test.txt', $this->getMockStorage(), null, [
			'permissions' => Constants::PERMISSION_ALL
		], null);

		$file = new File($view, $info);

		// action
		$thrown = false;
		try {
			// beforeMethod locks
			$view->lockFile($info->getPath(), ILockingProvider::LOCK_SHARED);

			$file->put($this->getStream('test data'));

			// afterMethod unlocks
			$view->unlockFile($info->getPath(), ILockingProvider::LOCK_SHARED);
		} catch (FileLocked $e) {
			$thrown = true;
		}

		$this->assertTrue($thrown);
		$this->assertEmpty($this->listPartFiles($view, ''), 'No stray part files');
	}

	/**
	 * Test exception during final rename in chunk upload mode
	 */
	public function testChunkedPutFailsFinalRename() {
		$view = new View('/' . $this->user . '/files');

		// simulate situation where the target file is locked
		$view->lockFile('/test.txt', ILockingProvider::LOCK_EXCLUSIVE);

		$_SERVER['HTTP_OC_CHUNKED'] = true;

		$info = new FileInfo('/' . $this->user . '/files/test.txt-chunking-12345-2-0', $this->getMockStorage(), null, [
			'permissions' => Constants::PERMISSION_ALL
		], null);
		$file = new File($view, $info);
		$file->acquireLock(ILockingProvider::LOCK_SHARED);
		$this->assertNull($file->put('test data one'));
		$file->releaseLock(ILockingProvider::LOCK_SHARED);

		$info = new FileInfo('/' . $this->user . '/files/test.txt-chunking-12345-2-1', $this->getMockStorage(), null, [
			'permissions' => Constants::PERMISSION_ALL
		], null);
		$file = new File($view, $info);

		// action
		$thrown = false;
		try {
			$file->acquireLock(ILockingProvider::LOCK_SHARED);
			$file->put($this->getStream('test data'));
			$file->releaseLock(ILockingProvider::LOCK_SHARED);
		} catch (FileLocked $e) {
			$thrown = true;
		}

		$this->assertTrue($thrown);
		$this->assertEmpty($this->listPartFiles($view, ''), 'No stray part files');
	}

	/**
	 * Test put file with invalid chars
	 */
	public function testSimplePutInvalidChars() {
		// setup
		$view = $this->getMockBuilder(View::class)
			->setMethods(['getRelativePath'])
			->getMock();
		$view->expects($this->any())
			->method('getRelativePath')
			->will($this->returnArgument(0));

		$info = new FileInfo('/*', $this->getMockStorage(), null, [
			'permissions' => Constants::PERMISSION_ALL
		], null);
		$file = new File($view, $info);

		// action
		$thrown = false;
		try {
			// beforeMethod locks
			$view->lockFile($info->getPath(), ILockingProvider::LOCK_SHARED);

			$file->put($this->getStream('test data'));

			// afterMethod unlocks
			$view->unlockFile($info->getPath(), ILockingProvider::LOCK_SHARED);
		} catch (\OCA\DAV\Connector\Sabre\Exception\InvalidPath $e) {
			$thrown = true;
		}

		$this->assertTrue($thrown);
		$this->assertEmpty($this->listPartFiles($view, ''), 'No stray part files');
	}

	/**
	 * Test setting name with setName() with invalid chars
	 *
	 * @expectedException \OCA\DAV\Connector\Sabre\Exception\InvalidPath
	 */
	public function testSetNameInvalidChars() {
		// setup
		$view = $this->getMockBuilder(View::class)
			->setMethods(['getRelativePath'])
			->getMock();

		$view->expects($this->any())
			->method('getRelativePath')
			->will($this->returnArgument(0));

		$info = new FileInfo('/*', $this->getMockStorage(), null, [
			'permissions' => Constants::PERMISSION_ALL
		], null);
		$file = new File($view, $info);
		$file->setName('/super*star.txt');
	}

	/**
	 */
	public function testUploadAbort() {
		// setup
		$view = $this->getMockBuilder(View::class)
			->setMethods(['rename', 'getRelativePath', 'filesize'])
			->getMock();
		$view->expects($this->any())
			->method('rename')
			->withAnyParameters()
			->will($this->returnValue(false));
		$view->expects($this->any())
			->method('getRelativePath')
			->will($this->returnArgument(0));
		$view->expects($this->any())
			->method('filesize')
			->will($this->returnValue(123456));

		$_SERVER['CONTENT_LENGTH'] = 12345;
		$_SERVER['REQUEST_METHOD'] = 'PUT';

		$info = new FileInfo('/test.txt', $this->getMockStorage(), null, [
			'permissions' => Constants::PERMISSION_ALL
		], null);

		$file = new File($view, $info);

		// action
		$thrown = false;
		try {
			// beforeMethod locks
			$view->lockFile($info->getPath(), ILockingProvider::LOCK_SHARED);

			$file->put($this->getStream('test data'));

			// afterMethod unlocks
			$view->unlockFile($info->getPath(), ILockingProvider::LOCK_SHARED);
		} catch (BadRequest $e) {
			$thrown = true;
		}

		$this->assertTrue($thrown);
		$this->assertEmpty($this->listPartFiles($view, ''), 'No stray part files');
	}

	/**
	 *
	 */
	public function testDeleteWhenAllowed() {
		// setup
		$view = $this->createMock(View::class);

		$view->expects($this->once())
			->method('unlink')
			->will($this->returnValue(true));

		$info = new FileInfo('/test.txt', $this->getMockStorage(), null, [
			'permissions' => Constants::PERMISSION_ALL
		], null);

		$file = new File($view, $info);

		// action
		$file->delete();
	}

	/**
	 * @expectedException \Sabre\DAV\Exception\Forbidden
	 */
	public function testDeleteThrowsWhenDeletionNotAllowed() {
		// setup
		$view = $this->createMock(View::class);

		$info = new FileInfo('/test.txt', $this->getMockStorage(), null, [
			'permissions' => 0
		], null);

		$file = new File($view, $info);

		// action
		$file->delete();
	}

	/**
	 * @expectedException \Sabre\DAV\Exception\Forbidden
	 */
	public function testDeleteThrowsWhenDeletionFailed() {
		// setup
		$view = $this->createMock(View::class);

		// but fails
		$view->expects($this->once())
			->method('unlink')
			->will($this->returnValue(false));

		$info = new FileInfo('/test.txt', $this->getMockStorage(), null, [
			'permissions' => Constants::PERMISSION_ALL
		], null);

		$file = new File($view, $info);

		// action
		$file->delete();
	}

	/**
	 * @expectedException \OCA\DAV\Connector\Sabre\Exception\Forbidden
	 */
	public function testDeleteThrowsWhenDeletionThrows() {
		// setup
		$view = $this->createMock(View::class);

		// but fails
		$view->expects($this->once())
			->method('unlink')
			->willThrowException(new ForbiddenException('', true));

		$info = new FileInfo('/test.txt', $this->getMockStorage(), null, [
			'permissions' => Constants::PERMISSION_ALL
		], null);

		$file = new File($view, $info);

		// action
		$file->delete();
	}

	/**
	 * Asserts hook call
	 *
	 * @param array $callData hook call data to check
	 * @param string $signal signal name
	 * @param string $hookPath hook path
	 */
	protected function assertHookCall($callData, $signal, $hookPath) {
		$this->assertEquals($signal, $callData['signal']);
		$params = $callData['params'];
		$this->assertEquals(
			$hookPath,
			$params[Filesystem::signal_param_path]
		);
	}

	/**
	 * Test whether locks are set before and after the operation
	 */
	public function testPutLocking() {
		$view = new View('/' . $this->user . '/files/');

		$path = 'test-locking.txt';
		$info = new FileInfo(
			'/' . $this->user . '/files/' . $path,
			$this->getMockStorage(),
			null,
			['permissions' => Constants::PERMISSION_ALL],
			null
		);

		$file = new File($view, $info);

		$this->assertFalse(
			$this->isFileLocked($view, $path, ILockingProvider::LOCK_SHARED),
			'File unlocked before put'
		);
		$this->assertFalse(
			$this->isFileLocked($view, $path, ILockingProvider::LOCK_EXCLUSIVE),
			'File unlocked before put'
		);

		$wasLockedPre = false;
		$wasLockedPost = false;
		$eventHandler = $this->getMockBuilder('\stdclass')
			->setMethods(['writeCallback', 'postWriteCallback'])
			->getMock();

		// both pre and post hooks might need access to the file,
		// so only shared lock is acceptable
		$eventHandler->expects($this->once())
			->method('writeCallback')
			->will($this->returnCallback(
				function () use ($view, $path, &$wasLockedPre) {
					$wasLockedPre = $this->isFileLocked($view, $path, ILockingProvider::LOCK_SHARED);
					$wasLockedPre = $wasLockedPre && !$this->isFileLocked($view, $path, ILockingProvider::LOCK_EXCLUSIVE);
				}
			));
		$eventHandler->expects($this->once())
			->method('postWriteCallback')
			->will($this->returnCallback(
				function () use ($view, $path, &$wasLockedPost) {
					$wasLockedPost = $this->isFileLocked($view, $path, ILockingProvider::LOCK_SHARED);
					$wasLockedPost = $wasLockedPost && !$this->isFileLocked($view, $path, ILockingProvider::LOCK_EXCLUSIVE);
				}
			));

		Util::connectHook(
			Filesystem::CLASSNAME,
			Filesystem::signal_write,
			$eventHandler,
			'writeCallback'
		);
		Util::connectHook(
			Filesystem::CLASSNAME,
			Filesystem::signal_post_write,
			$eventHandler,
			'postWriteCallback'
		);

		// beforeMethod locks
		$view->lockFile($path, ILockingProvider::LOCK_SHARED);

		$this->assertNotEmpty($file->put($this->getStream('test data')));

		// afterMethod unlocks
		$view->unlockFile($path, ILockingProvider::LOCK_SHARED);

		$this->assertTrue($wasLockedPre, 'File was locked during pre-hooks');
		$this->assertTrue($wasLockedPost, 'File was locked during post-hooks');

		$this->assertFalse(
			$this->isFileLocked($view, $path, ILockingProvider::LOCK_SHARED),
			'File unlocked after put'
		);
		$this->assertFalse(
			$this->isFileLocked($view, $path, ILockingProvider::LOCK_EXCLUSIVE),
			'File unlocked after put'
		);
	}

	/**
	 * Returns part files in the given path
	 *
	 * @param View $userView view which root is the current user's "files" folder
	 * @param string $path path for which to list part files
	 *
	 * @return array list of part files
	 */
	private function listPartFiles(View $userView = null, $path = '') {
		if ($userView === null) {
			$userView = Filesystem::getView();
		}
		$files = [];
		list($storage, $internalPath) = $userView->resolvePath($path);
		if($storage instanceof Local) {
			$realPath = $storage->getSourcePath($internalPath);
			$dh = opendir($realPath);
			while (($file = readdir($dh)) !== false) {
				if (substr($file, strlen($file) - 5, 5) === '.part') {
					$files[] = $file;
				}
			}
			closedir($dh);
		}
		return $files;
	}

	/**
	 * returns an array of file information filesize, mtime, filetype,  mimetype
	 * 
	 * @param string $path
	 * @param View $userView
	 * @return array
	 */
	private function getFileInfos($path = '', View $userView = null) {
		if ($userView === null) {
			$userView = Filesystem::getView();
		}
		return [
				"filesize" => $userView->filesize($path),
				"mtime" => $userView->filemtime($path),
				"filetype" => $userView->filetype($path),
				"mimetype" => $userView->getMimeType($path)
		];
	}

	/**
	 * @expectedException \Sabre\DAV\Exception\ServiceUnavailable
	 */
	public function testGetFopenFails() {
		$view = $this->getMockBuilder(View::class)
			->setMethods(['fopen'])
			->getMock();
		$view->expects($this->atLeastOnce())
			->method('fopen')
			->will($this->returnValue(false));

		$info = new FileInfo('/test.txt', $this->getMockStorage(), null, [
			'permissions' => Constants::PERMISSION_ALL
		], null);

		$file = new File($view, $info);

		$file->get();
	}

	/**
	 * @expectedException \OCA\DAV\Connector\Sabre\Exception\Forbidden
	 */
	public function testGetFopenThrows() {
		$view = $this->getMockBuilder(View::class)
			->setMethods(['fopen'])
			->getMock();
		$view->expects($this->atLeastOnce())
			->method('fopen')
			->willThrowException(new ForbiddenException('', true));

		$info = new FileInfo('/test.txt', $this->getMockStorage(), null, [
			'permissions' => Constants::PERMISSION_ALL
		], null);

		$file = new File($view, $info);

		$file->get();
	}

	/**
	 * @expectedException \Sabre\DAV\Exception\NotFound
	 */
	public function testGetThrowsIfNoPermission() {
		$view = $this->getMockBuilder(View::class)
			->setMethods(['fopen'])
			->getMock();
		$view->expects($this->never())
			->method('fopen');

		$info = new FileInfo('/test.txt', $this->getMockStorage(), null, [
			'permissions' => Constants::PERMISSION_CREATE // no read perm
		], null);

		$file = new File($view, $info);

		$file->get();
	}
}
