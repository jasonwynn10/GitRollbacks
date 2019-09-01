<?php
declare(strict_types=1);
namespace jasonwynn10\GitRollbacks;

use czproject\GitPHP\GitException;
use czproject\GitPHP\GitRepository;
use pocketmine\scheduler\AsyncTask;

class GitCommitTask extends AsyncTask {

	/** @var string */
	private $worldFolder, $timestamp, $gitFolder, $levelName;

	public function __construct(string $gitFolder, string $worldFolder, string $timestamp, string $levelName) {
		$this->gitFolder = $gitFolder;
		$this->worldFolder = $worldFolder;
		$this->timestamp = $timestamp;
		$this->levelName = $levelName;
	}

	/**
	 * Actions to execute when run
	 *
	 * @return void
	 * @throws GitException
	 */
	public function onRun() {
		$git = new GitRepository($this->gitFolder);
		Main::recursiveCopyAddGit($this->worldFolder, $this->gitFolder, $git);
		$git->addAllChanges();
		$git->commit($this->levelName." ".$this->timestamp);
	}
}