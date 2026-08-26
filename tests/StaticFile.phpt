<?php

declare(strict_types=1);

use Donut\Gui\StaticFile;
use Nette\Utils\FileSystem;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

$root = TEMP_DIR . '/www';
FileSystem::createDir($root . '/assets');
FileSystem::write($root . '/assets/bootstrap.min.css', 'body{}');
FileSystem::write($root . '/index.php', '<?php');
FileSystem::write(TEMP_DIR . '/secret.txt', 'SECRET');

// an existing file under the docroot should be left to the server
Assert::true(StaticFile::shouldServe($root, '/assets/bootstrap.min.css'));
Assert::true(StaticFile::shouldServe($root, '/assets/bootstrap.min.css?v=1'));

// the router doesn't hand itself off. The file exists and lies under the
// docroot, but handing it to the server would mean an empty 200: the server
// would run it as the requested script and `return false` at the top level
// would end it with no output. This is decided by the resolved path, not
// the URI string.
Assert::false(StaticFile::shouldServe($root, '/index.php'));
Assert::false(StaticFile::shouldServe($root, '/./index.php'));
Assert::false(StaticFile::shouldServe($root, '/assets/../index.php'));

// another .php under the docroot isn't the router's concern — it's served like any file
FileSystem::write($root . '/other.php', '<?php');
Assert::true(StaticFile::shouldServe($root, '/other.php'));

// a nonexistent file belongs to the application
Assert::false(StaticFile::shouldServe($root, '/'));
Assert::false(StaticFile::shouldServe($root, '/?presenter=Workflow&action=edit'));
Assert::false(StaticFile::shouldServe($root, '/assets/gone.css'));

// a directory isn't a file
Assert::false(StaticFile::shouldServe($root, '/assets'));

// a path traversal out of the docroot. Without this check, the built-in
// server returns an empty 200: the file exists, the router lets it through,
// the server then refuses to serve it. The content doesn't leak, but a 200
// response with an empty body makes no sense.
Assert::false(StaticFile::shouldServe($root, '/../secret.txt'));
Assert::false(StaticFile::shouldServe($root, '/assets/../../secret.txt'));
Assert::false(StaticFile::shouldServe($root, '/..%2fsecret.txt'));
Assert::false(StaticFile::shouldServe($root, '/etc/passwd'));

// a null byte in the path. realpath() throws a ValueError on it, and this
// guard runs before Bootstrap::boot(), so it would be a bare 500 instead of
// an error page.
Assert::false(StaticFile::shouldServe($root, '/assets/x%00.css'));
Assert::false(StaticFile::shouldServe($root, '/assets/bootstrap.min.css%00.txt'));
Assert::false(StaticFile::shouldServe($root, "/assets/x\0.css"));

// the docroot prefix must be compared including the separator — a sibling
// directory with the same starting name must not pass
FileSystem::write(TEMP_DIR . '/wwwother/file.txt', 'x');
Assert::false(StaticFile::shouldServe($root, '/../wwwother/file.txt'));
