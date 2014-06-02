<?php
require_once('Error.php');
require_once('config.php');

/**
 * Presents a single Dota 2 match
 * @todo This should have ALL the information about the match, inc players and so on
 */
class Match {
	public $publicId;
	public $matchId;
	public $startTime;
	public $duration;
	public $winner;
	public $mode;
	public $players;
	public $skill;
	public $lobbyType;
	
	public function __construct() {
		$args = func_get_args();
		switch (func_num_args()):
			case 1:
				$this->matchId = (int) $args[0];
				break;
		endswitch;
	}
	
	public function fetchFromApi() {
		if (!isset($this->matchId)) {
			Error::outputError('Can\'t fetch from API: No ID given', 'Match->fetchFromApi');
			return false;
		}
		
		if ($json = file_get_contents('https://api.steampowered.com/IDOTA2Match_570/GetMatchDetails/V001/?match_id=' . $this->matchId . '&key=' . API_KEY)) {
			$db = PdoFactory::getInstance(DB_CONNECTION, DB_USER, DB_PW);
			$matchData = json_decode($json, true);
			// @todo populate the fields from match_players if available
			$this->startTime = $matchData['result']['start_time'];
			$this->duration = $matchData['result']['duration'];
			$this->winner = $matchData['result']['radiant_win'] == true ? 1 : 0;
			$this->mode = $matchData['result']['game_mode'];
			$this->lobbyType = $matchData['result']['lobby_type'];
			$this->players = $matchData['result']['players'];
		}
	}
	
	public function saveToDb() {
		$db = PdoFactory::getInstance(DB_CONNECTION, DB_USER, DB_PW);
		$db->beginTransaction();
		$sql = 'INSERT INTO ' . DB_TABLE_PREFIX . 'match SET 
			match_id = :id, start_time = :start_time, duration = :duration, winner = :winner, mode = :mode, skill = :skill, lobby_type = :lobby_type
			ON DUPLICATE KEY UPDATE start_time = :start_time, duration = :duration, winner = :winner, mode = :mode, skill = :skill, lobby_type = :lobby_type';
		try {
			$stmt = $db->prepare($sql);
			$stmt->execute(array(':id' => $this->matchId, ':start_time' => $this->startTime, ':duration' => $this->duration, ':winner' => $this->winner, ':mode' => $this->mode, ':skill' => $this->skill, ':lobby_type' => $this->lobbyType));
			$this->publicId = $db->lastInsertId();
			
			if (is_array($this->players)) {
				foreach ($this->players AS $p) {
					$playerSql = 'INSERT INTO ' . DB_TABLE_PREFIX . 'match_player SET account_id = :account_id, match_id = :match_id, hero_id = :hero_id, position = :position';
					$stmt = $db->prepare($playerSql);
					$stmt->execute(array(':account_id' => $p['account_id'], ':match_id' => $this->matchId, ':hero_id' => $p['hero_id'], ':position' => $p['player_slot']));
				}
			}
			
			$db->commit();
		}
		catch (PDOException $e) {
			Error::outputError('Failed to insert match data to database', $e->getMessage(), 1);
			$db->rollBack();
		}
	}
	
	/**
	 * Loads and populates object with information from DB
	 * If both matchId and publicId are given, matchId is used
	 */
	public function loadFromDb($matchId = false, $publicId = false) {
		if ($matchId) {
			$this->matchId = $matchId;
		} 
		if ($publicId) {
			$this->publicId = $publicId;
		}
		
		try {
			$db = PdoFactory::getInstance(DB_CONNECTION, DB_USER, DB_PW);
			if ($this->matchId) {
				$matchSql = 'SELECT public_id, match_id, start_time, duration, winner, mode, skill, lobby_type FROM ' . DB_TABLE_PREFIX . 'match WHERE match_id = ?';
				$searchId = $this->matchId;
			} 
			elseif ($this->publicId) {
				$matchSql = 'SELECT public_id, match_id, start_time, duration, winner, mode, skill, lobby_type FROM ' . DB_TABLE_PREFIX . 'match WHERE public_id = ?';
				$searchId = $this->publicId;
			}
			else {
				throw New exception('No matchId or publicId given');
			}
			
			$stmt = $db->prepare($matchSql);
			$stmt->execute(array($searchId));
			
			$row = $stmt->fetch(PDO::FETCH_ASSOC);
			$this->publicId = $row['public_id'];
			$this->matchId = $row['match_id'];
			$this->startTime = $row['start_time'];
			$this->duration = $row['duration'];
			$this->winner = $row['winner'];
			$this->mode = $row['mode'];
			$this->skill = $row['skill'];
			$this->lobbyType = $row['lobby_type'];
			
			$stmt = $db->prepare('SELECT account_id, hero_id, position FROM ' . DB_TABLE_PREFIX . 'match_player WHERE match_id = ?');
			$stmt->execute(array($this->matchId));
			$this->players = array();
			
			if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
				do {
					list($team, $position) = $this->parsePlayerPosition($row['position']);				
					$this->players[] = array('account_id' => $row['account_id'], 'hero_id' => $row['hero_id'], 'team' => $team, 'position' => $position);
				} while ($row = $stmt->fetch(PDO::FETCH_ASSOC));
			}
			else {
				return false;
			}
		}
		catch (Exception $e) {
			Error::outputError('Can\'t load match information from database', $e->getMessage(), 1);
			return false;
		}
	}
	
	function parsePlayerPosition($b) {
		//echo ($pos & 1) . " : ";
		$team = ($b >> 7) == 1 ? 'd' : 'r';
		$position = 1 + ($b & 1) + ($b & 2) + ($b & 4);
		
		return array($team, $position);
	}
	
	function isValid($debug = false) {
		if (is_array($this->players)) {
			foreach ($this->players AS $p) {
				if ($p['leaver_status'] === 1 || $p['hero_id'] === 0) {
					if ($debug) { echo "Match no good, leaver_status {$p['leaver_status']}, hero_id {$p['hero_id']}\n"; }
					return false;
				}
			}
		}
		if ($this->duration < 600) {
			if ($debug) { echo "Match no good, duration {$this->duration}\n"; }
			return false;
		}
		elseif ($this->mode > 5) {
			if ($debug) { echo "Match no good, mode {$this->mode}\n"; }
			return false;
		}
		elseif ($this->lobbyType > 0) {
			if ($debug) { echo "Match no good, lobbyType {$this->mode}\n"; }
			return false;
		}
		else {
			return true;
		}
	}
}