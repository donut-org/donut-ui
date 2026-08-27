<?php

declare(strict_types=1);

use Nette\Http\IResponse;
use Nette\Http\Request as HttpRequest;
use Nette\Http\Session;
use Nette\Http\UrlScript;


/**
 * A session that never reaches PHP's session module.
 *
 * The presenters need one only for flash messages, and a real session is
 * hostile in this environment twice over: session_set_save_handler() in its
 * object form registers a write at request shutdown, and that write lands
 * inside Tester's own output handler ("Cannot use output buffering in output
 * buffering display handlers"). On top of that nette/tester runs tests in
 * parallel processes, which would share session files.
 *
 * SessionSection reads and writes `$_SESSION['__NF']` directly, so overriding
 * the four methods that decide whether the session is running is enough to
 * keep everything in that array — no module, no files, no shutdown hook.
 */
final class MemorySession extends Session
{
	private bool $running = false;


	public function __construct(IResponse $response)
	{
		// The parent keeps the request only to compare session cookies, which
		// is on a path this session never takes.
		parent::__construct(new HttpRequest(new UrlScript('http://localhost/')), $response);
	}


	public function start(): void
	{
		$this->autoStart(forWrite: true);
	}


	public function autoStart(bool $forWrite): void
	{
		$this->running = true;
		$_SESSION['__NF'] ??= ['DATA' => [], 'META' => []];
	}


	public function isStarted(): bool
	{
		return $this->running;
	}


	public function exists(): bool
	{
		return $this->running;
	}
}
