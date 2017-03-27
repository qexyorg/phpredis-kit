<?php
/**
 * @author Qexy (qexy.org)
 *
 * @license BSD (https://opensource.org/licenses/BSD-3-Clause)
 *
 * @package rediso
 *
 * @version 0.0.3
 *
 * @example $db = new rediso();
*/

class rediso{
	// Redis object
	public $rds = false;

	// Most parent key
	public $table = 'rediso';

	// System table for settings
	private $tablesys = '_sys';

	// Last selection data
	private $lastCache = [];

	public $query_num = 0;

	// Main list key in the table
	private static $listkey = 'list';

	// Primary key in the table
	private static $primarykey = 'primary';

	// Auto increment system key
	private static $incrementkey = 'increment';

	// Connection timeout
	private static $timeout = 3;

	// See errors 0 - disabled | 1 - only titles | 2 - full exception
	private static $debug = 2;

	/**
	 * @method public
	 *
	 * @name connect
	 *
	 * @param opt - array options @see below
	 * @var opt['host'] - string
	 * @var opt['port'] - integer
	 * @var opt['password'] - string
	 * @var opt['database'] - integer
	 *
	 * @example rediso->connect(['password' => 'MySuperPassw0rD', 'database' => 3]);
	 *
	 * @return boolean
	*/
	public function connect($opt=[]){

		$this->tablesys = $this->table.$this->tablesys;

		$opt = [
			'host' => (isset($opt['host'])) ? $opt['host'] : '127.0.0.1',
			'port' => (isset($opt['port'])) ? intval($opt['port']) : 6379,
			'password' => (isset($opt['password'])) ? $opt['password'] : '',
			'database' => (isset($opt['database'])) ? intval($opt['database']) : 0
		];

		if(!class_exists('Redis')){ $this->debug($e, 'class Redis not found', __LINE__); return false; }

		$this->rds = new Redis();

		try{
			$this->rds->connect($opt['host'], $opt['port'], self::$timeout);
		}catch(Exception $e){
			$this->debug($e, 'connection failed', __LINE__);
			return false;
		}

		if(!$this->rds->auth($opt['password'])){
			$this->debug('', 'incorrect password', __LINE__);
			return false;
		}

		if(!$this->rds->select($opt['database'])){
			$this->debug('', 'incorrect database', __LINE__);
			return false;
		}

		if(!$this->rds->ping()){
			$this->debug('', 'database unavailable', __LINE__);
			return false;
		}

		return true;
	}

	/**
	 * @method private
	 *
	 * @name debug
	 *
	 * @param e - string (Exception)
	 *
	 * @param title - string (Error name)
	 *
	 * @return void || die
	*/
	private function debug($e='', $title='', $line=0){
		if(self::$debug<=0){ return; }


		echo '<p>[<b>Redis</b>] Error: <u>'.$title.'</u> on line #'.$line.'</p>';

		if(self::$debug>=2){ echo '<p>'.$e.'</p>'; }

		exit;
	}

	/**
	 * @method private
	 *
	 * @name pg - path generator
	 *
	 * @param path - string
	 * @param primary - string
	 *
	 * @example this->pg('example');
	 * @return string
	*/
	private function pg($path, $primary=[]){
		if(sizeof($primary)==2){ return $this->table.':'.$path.':'.self::$primarykey.':'.$primary[0].':'.$primary[1]; }

		return $this->table.':'.$path.':'.self::$listkey;
	}

	/**
	 * @method public
	 *
	 * @name hAdd
	 *
	 * @param opt - array options @see below
	 * @var opt['path'] - string
	 * @var opt['data'] - array
	 * @var opt['primary'] - array
	 *
	 * @global Warning! Ton't use a long strings for primary
	 * @example rediso->hAdd(['path' => 'mytable', 'data' => ['key1' => 'value1', 'key2' => 'value2'], 'primary' => ['key2']]);
	 * @return boolean || integer
	*/
	public function hAdd($opt=[]){
		if(!isset($opt['path']) || empty($opt['path'])){ $this->debug('hAdd', 'param path is not set', __LINE__); return false; }

		if(!isset($opt['data']) || empty($opt['data'])){ $this->debug('hAdd', 'param data is not set', __LINE__); return false; }

		if(isset($opt['data']['id'])){ $this->debug('hAdd', 'data key id is reserved', __LINE__); return false; }

		$id = $this->hLastIncrement($opt['path']);

		$opt['data']['id'] = $id;

		$value = json_encode($opt['data'], JSON_UNESCAPED_UNICODE);

		$this->query_num++;

		$insert_sys = $this->rds->hSet($this->tablesys.':'.self::$incrementkey, $opt['path'], $id);
		if($insert_sys===false){ $this->debug('hAdd', 'insert sys error', __LINE__); return false; }

		$this->query_num++;

		$insert = $this->rds->hSet($this->pg($opt['path']), $id, $value);
		if($insert===false){ $this->debug('hAdd', 'insert error', __LINE__); return false; }

		if(isset($opt['primary']) && !empty($opt['primary'])){
			foreach($opt['primary'] as $i => $key){
				$this->query_num++;
				
				$primary = $this->rds->hSet($this->pg($opt['path'], [$key, $opt['data'][$key]]), $id, $value);
				if($primary===false){ $this->debug('hAdd', 'primary error', __LINE__); $error = true; break; /*continue;*/ }
			}
		}

		return (isset($error)) ? false : $id;
	}

	/**
	 * @method public
	 *
	 * @name hUpdate
	 *
	 * @param opt - array options @see below
	 * @var opt['path'] - string
	 * @var opt['data'] - array
	 * @var opt['id'] - string || integer
	 *
	 * @example rediso->hUpdate(['path' => 'mytable', 'id' => 10, 'data' => ['key1' => 'value1', 'key2' => 'value2']]);
	 * @return boolean || integer
	*/
	public function hUpdate($opt=[]){
		if(!isset($opt['path']) || empty($opt['path'])){ $this->debug('hUpdate', 'param path is not set', __LINE__); return false; }

		if(!isset($opt['id']) || empty($opt['id'])){ $this->debug('hUpdate', 'param id is not set', __LINE__); return false; }

		if(!isset($opt['data']) || empty($opt['data'])){ $this->debug('hUpdate', 'param data is not set', __LINE__); return false; }

		if(isset($opt['data']['id'])){ $this->debug('hUpdate', 'data key id is reserved', __LINE__); return false; }

		$this->query_num++;

		$query = $this->rds->hGet($this->pg($opt['path']), intval($opt['id']));
		$data = $newdata = json_decode($query, true);

		foreach($data as $key => $value){
			if(isset($opt['data'][$key])){ $newdata[$key] = $opt['data'][$key]; }
		}

		$this->query_num++;
		$primary = $this->rds->keys($this->table.':'.$opt['path'].':'.self::$primarykey.':*');

		$newjson = json_encode($newdata);

		if(!empty($primary)){
			foreach($primary as $i => $path){
				$expl = explode(':', $path);

				// remove last key from the path
				array_pop($expl);

				// get pre-last key from the path
				$key = array_pop($expl);

				// make new path
				$newpath = implode(':', $expl).':'.$key.':'.$newdata[$key];

				// Rename updated keys in primary
				if(isset($newdata[$key])){
					$this->query_num++;
					$rename = $this->rds->rename($path, $newpath);
					if($rename===false){ $this->debug('hUpdate', 'rename error', __LINE__); $error = true; break; /*continue;*/ }
				}

				$this->query_num++;
				$update = $this->rds->hSet($newpath, $data['id'], $newjson);
				if($update===false){ $this->debug('hUpdate', 'set error', __LINE__); $error = true; break; /*continue;*/ }
			}

			if(isset($error)){ return false; }
		}

		$this->query_num++;
		$update = $this->rds->hSet($this->pg($opt['path']), $data['id'], $newjson);
		if($update===false){ $this->debug('hUpdate', 'set error', __LINE__); return false; }

		return true;
	}

	/**
	 * @method public
	 *
	 * @name hDelete
	 *
	 * @param opt - array options @see below
	 * @var opt['path'] - string
	 * @var opt['id'] - string || integer
	 *
	 * @example rediso->hDelete(['path' => 'mytable', 'id' => 10]);
	 * @return boolean
	*/
	public function hDelete($path, $id){
		$id = intval($id);
		if(empty($path)){ $this->debug('hDelete', 'param path is not set', __LINE__); return false; }

		$this->query_num++;

		$delete = $this->rds->hDel($this->pg($path), $id);
		if($delete===false){ $this->debug('hDelete', 'delete error', __LINE__); return false; }

		$this->query_num++;

		$primary = $this->rds->keys($this->table.':'.$path.':'.self::$primarykey.':*');

		if(!empty($primary)){
			foreach($primary as $i => $p){

				$this->query_num++;

				$delete = $this->rds->hDel($p, $id);
				if($delete===false){ $this->debug('hDelete', 'delete error', __LINE__); $error = true; break; /*continue;*/ }
			}

			if(isset($error)){ return false; }
		}

		$this->query_num++;

		$delete = $this->rds->hDel($this->pg($path), $id);
		if($delete===false){ $this->debug('hUpdate', 'delete error', __LINE__); return false; }

		return true;
	}

	/**
	 * @method public
	 *
	 * @name hLastIncrement
	 *
	 * @param path - string key
	 *
	 * @example rediso->hLastIncrement(['path' => 'MySuperPassw0rD', 'database' => 3]);
	 *
	 * @return integer
	*/
	public function hLastIncrement($path){
		$key = $this->rds->hGet($this->tablesys.':'.self::$incrementkey, $path);

		$this->query_num++;

		if($key===false || empty($key)){ return 1; }

		return intval($key)+1;
	}

	/**
	 * @method public
	 *
	 * @name hSearchAll
	 *
	 * @param opt - array options @see below
	 * @var opt['path'] - string
	 * @var opt['where'] - array
	 * @var opt['sort'] - string
	 * @var opt['orderby'] - string
	 * @var opt['limit'] - integer || array
	 * @var opt['search'] - array
	 *
	 * @example rediso->hSearchAll(['path' => 'mytable', 'sort' => 'desc', 'limit' => 1]);
	 *
	 * @return array
	*/
	public function hSearchAll($opt=[]){

		if(!isset($opt['path']) || empty($opt['path'])){ $this->debug('hSearchAll', 'param path is not set', __LINE__); return false; }

		$opt = [
			'path' => $opt['path'],
			'where' => (isset($opt['where']) && !empty($opt['where']) && is_array($opt['where'])) ? $opt['where'] : [],
			'sort' => (isset($opt['sort'])) ? $opt['sort'] : 'asc',
			'orderby' => (isset($opt['orderby'])) ? $opt['orderby'] : '',
			'search' => (isset($opt['search']) && !empty($opt['search'])) ? $opt['search'] : [],
			'limit' => (isset($opt['limit'])) ? $opt['limit'] : 0,
		];

		$pg = $this->pg($opt['path']);

		$pathkey = md5($pg);

		$result = ['count' => 0, 'countAll' => 0, 'data' => []];

		if(!isset($this->lastCache[$pathkey])){
			$this->query_num++;
			$this->lastCache[$pathkey] = ['opt' => $opt, 'data' => $this->rds->hGetAll($pg)];
		}

		$this->lastCache[$pathkey]['opt'] = $opt;
		$data = $this->lastCache[$pathkey]['data'];

		if(empty($data)){ return $result; }

		// Size of data
		$result['countAll'] = sizeof($data);

		// each main data
		foreach($data as $key => $ar){

			if(!is_array($ar)){
				$ar = json_decode($ar, true);
			}

			// Check where options
			if(!empty($opt['where'])){

				// Filter where options
				foreach($opt['where'] as $wk => $wv){
					if(sizeof($wv)!=3){ $this->debug('hSearchAll', 'param where is not match', __LINE__); return false; break 2; }

					// ['>', '<', '=', '<=', '>=', '!=']

					// If array have 3 elements like ['id', '>', '3']
					if(!isset($ar[$wv[0]])){ $this->debug('hSearchAll', 'undefined where key ', __LINE__); return false; break 2; }

					
					switch($wv[1]){
						case '>': if($ar[$wv[0]]>$wv[2]){ $result['data'][$ar['id']] = $ar; } break;

						case '<': if($ar[$wv[0]]<$wv[2]){ $result['data'][$ar['id']] = $ar; } break;

						case '=': if($ar[$wv[0]]==$wv[2]){ $result['data'][$ar['id']] = $ar; } break;

						case '<=': if($ar[$wv[0]]<=$wv[2]){ $result['data'][$ar['id']] = $ar; } break;

						case '>=': if($ar[$wv[0]]>=$wv[2]){ $result['data'][$ar['id']] = $ar; } break;

						case '!=': if($ar[$wv[0]]!=$wv[2]){ $result['data'][$ar['id']] = $ar; } break;

						default: $this->debug('hSearchAll', 'undefined where operator', __LINE__); return false; break 3;
					}
				}
			}

			// Check search options
			if(!empty($opt['search'])){
				if(is_array($opt['search'])){
					foreach($opt['search'] as $sk => $sv){
						if(!isset($ar[$sk])){ $this->debug('hSearchAll', 'undefined search key', __LINE__); return false; break 2; }

						if(mb_stristr($ar[$sk], $sv, false, 'UTF-8')!==false){
							$result['data'][$ar['id']] = $ar;
						}
					}
				}else{
					$stringify = implode('|', $ar);
					if(mb_stristr($stringify, $opt['search'], false, 'UTF-8')!==false){
						$result['data'][$ar['id']] = $ar;
					}
				}
			}

			if(!isset($ar[$opt['orderby']])){ $this->debug('hSearchAll', 'undefined orderby key', __LINE__); return false; break; }

			if(isset($result['data'][$ar['id']])){
				$sort[$ar['id']] = $result['data'][$ar['id']][$opt['orderby']];
			}
			
		}

		if(!empty($opt['sort']) && !empty($sort)){
			if($opt['sort']=='desc'){
				array_multisort($sort, SORT_DESC, $result['data']);
			}else{
				array_multisort($sort, SORT_ASC, $result['data']);
			}
		}

		if($opt['limit']>0 && !empty($opt['limit'])){
			if(is_array($opt['limit'])){
				$result['data'] = (sizeof($opt['limit'])==2) ? array_slice($result['data'], $opt['limit'][0], $opt['limit'][1]) : array_slice($result['data'], 0, $opt['limit'][0]);
			}elseif(is_numeric($opt['limit'])){
				$result['data'] = array_slice($result['data'], 0, $opt['limit']);
			}
		}

		$result['count'] = sizeof($result['data']);

		$this->lastCache[$pathkey]['data'] = $result['data'];

		return $result;
	}
}

?>