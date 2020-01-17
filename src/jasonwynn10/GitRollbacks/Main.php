<?php
declare(strict_types=1);
namespace jasonwynn10\GitRollbacks;

use pocketmine\event\level\LevelLoadEvent;
use pocketmine\event\level\LevelSaveEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerDataSaveEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\IPlayer;
use pocketmine\level\Level;
use pocketmine\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\plugin\PluginException;
use pocketmine\Server;
use pocketmine\utils\Utils;

class Main extends PluginBase implements Listener {
	public function onLoad() : void {
		if(!in_array(Utils::getOS(), ["win", "linux", "mac"])) {
			throw new PluginException("GitRollbacks is currently designed to function on Windows and Linux based devices. Your device is not compatible."); // TODO: BSD support
		}
		if(!$this->isGitInstalled()) {
			Server::getInstance()->getAsyncPool()->submitTask(new GitInstallTask($this->getDataFolder()));
			throw new PluginException("Git is not installed. Plugin startup will be delayed until the installation is completed.");
		}
	}

	public function onEnable() : void {
		$this->getServer()->getPluginManager()->registerEvents($this, $this);
		$this->getServer()->getCommandMap()->register("rollback", new RollbackCommand($this));
	}

	/**
	 * @param string $source
	 * @param string $destination
	 * @param GitRepository|null $git
	 *
	 * @throws GitException
	 */
	public static function recursiveCopyAddGit(string $source, string $destination, GitRepository $git = null) : void {
		if(is_dir($source)) {
			$dir = opendir($source);
			@mkdir($destination);
			while(false !== ( $file = readdir($dir)) ) {
				if (( $file != '.' ) && ( $file != '..' )) {
					if ( is_dir($source . '/' . $file) ) {
						self::recursiveCopyAddGit($source . '/' . $file, $destination . '/' . $file);
					}else {
						touch($destination . '/' . $file);
						copy($source . '/' . $file, $destination . '/' . $file);
						if($git !== null)
							$git->addFiles([$destination.DIRECTORY_SEPARATOR.$file]);
					}
				}
			}
			closedir($dir);
		}else{
			touch($destination . '/' . basename($source));
			copy(realpath($source), $destination . '/' . basename($source));
			if($git !== null)
				$git->addFiles([$destination . '/' . basename($source)]);
		}
	}

	/**
	 * @param \DateTime $timestamp
	 * @param Level $level
	 * @param bool $force
	 *
	 * @return bool
	 * @throws GitException
	 */
	public function rollbackLevelFromTimestamp(\DateTime $timestamp, Level $level, bool $force = false) : bool {
		$return = $this->getServer()->unloadLevel($level, $force); // force unload for rollback of default world
		if(!$return)
			return false;
		$git = new GitRepository($this->getDataFolder()."worlds".DIRECTORY_SEPARATOR.$level->getFolderName());
		$commit = $this->findCommitByTimestamp($timestamp, $git);
		if($this->getServer()->isRunning()) {
			$this->getServer()->getAsyncPool()->submitTask(new RollbackLevelTask($this->getDataFolder()."worlds".DIRECTORY_SEPARATOR.$level->getFolderName(), $level->getFolderName(), $commit, $force));
			return true;
		}
		$git->checkout($commit);
		$count = 1;
		foreach($git->getBranches() ?? [] as $branch) {
			if($branch === "master")
				continue;
			$count = substr($branch, 8);
			$count += (int)$count;
		}
		$git->createBranch("Rollback".$count, true);
		if(!$return) {
			$ret = false;
		}else{
			self::recursiveCopyAddGit($this->getDataFolder()."worlds".DIRECTORY_SEPARATOR.$level->getFolderName(), $level->getProvider()->getPath());
			$ret = true;
		}
		return $ret;
	}

	/**
	 * @param \DateTime $timestamp
	 * @param IPlayer $player
	 * @param bool $force
	 *
	 * @return bool
	 * @throws GitException
	 */
	public function rollbackPlayerFromTimestamp(\DateTime $timestamp, IPlayer $player, bool $force = false) : bool {
		if($player instanceof Player) {
			$return = $player->kick("Your user information is being rolled back", false);
			if(!$return and $force) {
				$player->close($player->getLeaveMessage(), "Your user information is being rolled back");
			}elseif(!$return){
				return false;
			}
		}
		$git = new GitRepository($this->getDataFolder()."players");
		$commit = $this->findCommitByTimestamp($timestamp, $git);
		//$git->checkout($commit); don't rollback all player files
		if($this->getServer()->isRunning()) {
			$this->getServer()->getAsyncPool()->submitTask(new RollbackPlayerTask($this->getDataFolder()."players", $player->getName(), $commit, $force));
			return true;
		}
		$git->checkoutFile($commit, strtolower($player->getName()).".dat");
		$count = 1;
		foreach($git->getBranches() ?? [] as $branch) {
			if($branch === "master")
				continue;
			$count = substr($branch, 8);
			$count += (int)$count;
		}
		$git->createBranch("Rollback".$count, true);
		self::recursiveCopyAddGit($this->getDataFolder()."players", $this->getServer()->getDataPath()."players".DIRECTORY_SEPARATOR);
		return true;
	}

	/**
	 * @param string $commit
	 * @param Level $level
	 * @param bool $force
	 *
	 * @return bool
	 * @throws GitException
	 */
	public function rollbackLevelFromCommit(string $commit, Level $level, bool $force = false) : bool {
		$return = $this->getServer()->unloadLevel($level, $force); // force unload for rollback of default world
		if(!$return)
			return false;
		$git = new GitRepository($this->getDataFolder()."worlds".DIRECTORY_SEPARATOR.$level->getFolderName());
		$git->checkout($commit);
		$count = 1;
		foreach($git->getBranches() ?? [] as $branch) {
			if($branch === "master")
				continue;
			$count = substr($branch, 8);
			$count += (int)$count;
		}
		$git->createBranch("Rollback".$count, true);
		if($this->getServer()->isRunning()) {
			$this->getServer()->getAsyncPool()->submitTask(new RollbackLevelTask($this->getDataFolder()."worlds".DIRECTORY_SEPARATOR.$level->getFolderName(), $level->getFolderName(), $commit, $force));
		}
		self::recursiveCopyAddGit($this->getDataFolder()."worlds".DIRECTORY_SEPARATOR.$level->getFolderName(), $level->getProvider()->getPath());
		return true;
	}

	/**
	 * @param string $commit
	 * @param IPlayer $player
	 * @param bool $force
	 *
	 * @return bool
	 * @throws GitException
	 */
	public function rollbackPlayerFromCommit(string $commit, IPlayer $player, bool $force = false) : bool {
		if($player instanceof Player) {
			$return = $player->kick("Your user information is being rolled back", false);
			if(!$return and $force) {
				$player->close($player->getLeaveMessage(), "Your user information is being rolled back");
			}elseif(!$return) {
				return false;
			}
		}
		$git = new GitRepository($this->getDataFolder()."players");
		if($this->getServer()->isRunning()) {
			$this->getServer()->getAsyncPool()->submitTask(new RollbackPlayerTask($this->getDataFolder()."players", $player->getName(), $commit, $force));
			return true;
		}
		$git->checkoutFile($commit, strtolower($player->getName()).".dat");
		$count = 1;
		foreach($git->getBranches() ?? [] as $branch) {
			if($branch === "master")
				continue;
			$count = substr($branch, 8);
			$count += (int)$count;
		}
		$git->createBranch("Rollback".$count, true);
		self::recursiveCopyAddGit($this->getDataFolder()."players", $this->getServer()->getDataPath()."players".DIRECTORY_SEPARATOR);
		return true;
	}

	/**
	 * @param Level $level
	 *
	 * @return string
	 * @throws GitException
	 */
	public function getLastLevelCommit(Level $level) : string {
		$git = new GitRepository($this->getDataFolder()."worlds".DIRECTORY_SEPARATOR.$level->getFolderName());
		return $git->getLastCommitId();
	}

	/**
	 * @param IPlayer|null $player
	 *
	 * @return string
	 * @throws GitException
	 */
	public function getLastPlayerCommit(?IPlayer $player = null) : string {
		$git = new GitRepository($this->getDataFolder()."players");
		if($player instanceof IPlayer) {
			return $git->getLastFileCommitId(strtolower($player->getName()).".dat") ?? "";
		}
		return $git->getLastCommitId() ?? "";
	}

	/**
	 * @param \DateTime $timestamp
	 * @param GitRepository $git
	 *
	 * @return string
	 * @throws GitException
	 */
	public static function findCommitByTimestamp(\DateTime $timestamp, GitRepository $git) : string {
		$timestamp = $timestamp->format('Y-m-d H:i:s');
		$output = $git->execute(['log', '--grep='.$timestamp]);
		return substr($output[0], strlen("commit "));
	}

	/**
	 * @param LevelLoadEvent $event
	 *
	 * @throws GitException
	 */
	public function onWorldLoad(LevelLoadEvent $event) : void {
		$level = $event->getLevel();
		try{
			$initialCommit = true;
			GitRepository::init($this->getDataFolder()."worlds".DIRECTORY_SEPARATOR.$level->getFolderName());
		}catch(GitException $e) {
			$initialCommit = false;
		}

		$git = new GitRepository($this->getDataFolder()."worlds".DIRECTORY_SEPARATOR.$level->getFolderName());

		self::recursiveCopyAddGit($level->getProvider()->getPath(), $this->getDataFolder()."worlds".DIRECTORY_SEPARATOR.$level->getFolderName(), $git);

		if($initialCommit) {
			$git->addAllChanges();
			$git->commit("First Backup");
		}
	}

	/**
	 * @param LevelSaveEvent $event
	 */
	public function onWorldSave(LevelSaveEvent $event) : void {
		$gitFolder = $this->getDataFolder()."worlds".DIRECTORY_SEPARATOR.$event->getLevel()->getFolderName();
		$worldFolder = $event->getLevel()->getProvider()->getPath();
		$levelName = $event->getLevel()->getFolderName();
		$timestamp = (new \DateTime())->format('Y-m-d H:i:s');
		if($this->getServer()->isRunning()) {
			$this->getServer()->getAsyncPool()->submitTask(new GitCommitAsyncTask($gitFolder, $worldFolder, $timestamp, $levelName));
			return;
		}
		$this->getScheduler()->scheduleTask(new GitCommitTask($gitFolder, $worldFolder, $timestamp, $levelName));
	}

	/**
	 * @param PlayerJoinEvent $event
	 */
	public function onPlayerJoin(PlayerJoinEvent $event) {
		try{
			GitRepository::init($this->getDataFolder()."players");
			$git = new GitRepository($this->getDataFolder()."players");
			self::recursiveCopyAddGit($this->getServer()->getDataPath()."players", $this->getDataFolder()."players", $git);
			$git->addAllChanges();
			$git->commit("First Backup");
		}catch(GitException $e) {
			// do nothing
		}
	}

	/**
	 * @param PlayerDataSaveEvent $event
	 */
	public function onPlayerSave(PlayerDataSaveEvent $event) {
		$gitFolder = $this->getDataFolder()."players";
		$playerFile = $this->getServer()->getDataPath()."players".DIRECTORY_SEPARATOR.strtolower($event->getPlayerName()).".dat";
		$playerName = $event->getPlayerName();
		$timestamp = (new \DateTime())->format('Y-m-d H:i:s');
		if($this->getServer()->isRunning()) {
			$this->getServer()->getAsyncPool()->submitTask(new GitCommitAsyncTask($gitFolder, $playerFile, $timestamp, $playerName));
			return;
		}
		$this->getScheduler()->scheduleTask(new GitCommitTask($gitFolder, $playerFile, $timestamp, $playerName));
	}

	/**
	 * @return bool
	 */
	private function isGitInstalled() : bool {
		switch(Utils::getOS()) {
			case "linux":
				$ret = 0;
				$cmd = 'git help -g';
				try{
					exec($cmd . ' 2>&1', $output, $ret);
					// TODO: detect local installation
				}finally{
					return $ret == 0;
				}
			break;
			case "win":
				$ret = 0;
				$cmd = 'git help -g';
				try{
					exec($cmd . ' 2>&1', $output, $ret);
					if(file_exists($this->getDataFolder()."git".DIRECTORY_SEPARATOR."cmd".DIRECTORY_SEPARATOR."git.exe")) {
						exec("set PATH=%PATH%;".$this->getDataFolder()."git".DIRECTORY_SEPARATOR."cmd");
						//GitRepository::setGitInstallation($this->getDataFolder()."git".DIRECTORY_SEPARATOR."cmd".DIRECTORY_SEPARATOR."git.exe");
						exec($cmd . ' 2>&1', $output, $ret);
					}
				}finally{
					return $ret == 0;
				}
			break;
			case "mac": // TODO: is mac different?
				$ret = 0;
				$cmd = 'git help -g';
				try{
					exec($cmd . ' 2>&1', $output, $ret);
					// TODO: detect local installation
				}finally{
					return $ret == 0;
				}
			break;
			default:
				throw new PluginException("The OS of this device does not support git installation.");
		}
	}
}