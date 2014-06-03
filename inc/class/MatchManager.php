<?php
require_once('config.php');
require_once('Error.php');
require_once('Match.php');

/**
 * Retrieves Matches from database and from API
 * And manages the matches :|
 */
class MatchManager {
	/**
	 * Fetches matches from api, and saves them to the database
	 * @todo This really should return a list of matches
	 */
	public function fetchFromApi() {		
		$db = PdoFactory::getInstance(DB_CONNECTION, DB_USER, DB_PW);
		
		$maxMatchSeqNum = $this->getMaxMatchSeqNum();
		
		// get new match data
		$json = file_get_contents('https://api.steampowered.com/IDOTA2Match_570/GetMatchHistoryBySequenceNum/V001/?start_at_match_seq_num=' . $maxMatchSeqNum . '&key=' . API_KEY);

		$matches = json_decode($json, true);
		$matchList = array();
		// execute queries
		for($i = 0; $i < count($matches['result']['matches']); $i++) {
			$match = $matches['result']['matches'][$i];
				try {								
				// get detailed match data
				$matchObject = new Match($match['match_id']);
				$matchObject->startTime = $match['start_time'];
				$matchObject->duration = $match['duration'];
				$matchObject->winner = $match['radiant_win'] == true ? 1 : 0;
				$matchObject->mode = $match['game_mode'];
				$matchObject->players = $match['players'];
				$matchObject->lobbyType = $match['lobby_type'];
				$matchObject->matchSeqNum = $match['match_seq_num'];
				if ($matchObject->isValid() === true) {
					// test if match is already on the database, 
					// if it is, just go to the next one
					$testMatch = new Match($match['match_id']);
					if ($testMatch->loadFromDb() !== false) {
						continue;
					}
					$matchObject->saveToDb();
					$matchList[] = $matchObject;
				}
			}
			catch (PDOException $e) {
				Error::outputError('Failed to insert match/players data' . $e->getMessage(), $e->getMessage(), 1);
			}
		}
		return $matchList;
	}
	
	public function getRandomMatches($count = 10) {
		$count = (int) $count;
		$matches = array();
		
		try {
			$db = PdoFactory::getInstance(DB_CONNECTION, DB_USER, DB_PW);
			// get 10 random IDs from matches-table
			// http://jan.kneschke.de/projects/mysql/order-by-rand/
			$stmt = $db->prepare('
			SELECT r1.public_id
				FROM ' . DB_TABLE_PREFIX . 'match AS r1 JOIN
						 (SELECT (RAND() * (SELECT MAX(public_id) FROM ' . DB_TABLE_PREFIX . 'match)) AS public_id) AS r2
				WHERE r1.public_id >= r2.public_id
				ORDER BY r1.public_id ASC
				LIMIT ' . $count);
			
			$stmt->execute(array($count));
			while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
				$match = new Match();
				$match->loadFromDb(false, $row[0]);
				$matches[] = $match;
			}
			return $matches;
		}
		catch (Exception $e) {
			Error::outputError('Failed to get random matches', $e->getMessage(), 1);
		}
	}
	
	public function getMaxMatchSeqNum() {
		try {
			$db = PdoFactory::getInstance(DB_CONNECTION, DB_USER, DB_PW);
			$stmt = $db->prepare('SELECT MAX(match_seq_num) AS match_seq_num FROM ' . DB_TABLE_PREFIX . 'match');
			$stmt->execute();
			if ($row = $stmt->fetch(PDO::FETCH_NUM)) {
				return $row[0];
			}
		}
		catch (Exception $e) {
			Error::outputError('Failed to get max match_seq_number', $e->getMessage(), 1);
		}
	}
}