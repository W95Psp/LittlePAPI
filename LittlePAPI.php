<?php
	function get_method_paramsName($objectName, $methodName) {
		$f = new ReflectionMethod($objectName, $methodName);
		$result = array();
		foreach ($f->getParameters() as $param)
			$result[] = $param->name;
		return $result;
	}

	function is_function($f) {
	    return (is_string($f) && function_exists($f)) || (is_object($f) && ($f instanceof Closure));
	}

	abstract class LittlePAPI implements JsonSerializable {
		protected	$id;
		private		$isLoaded = false;
		private		$cache;
		private		$data;
		private		$relations = array();

		protected static $db;
		protected $loadRoutine = array();

		protected static $keys = array();
		protected static $tableName;
		protected static $relationsDescription;
		


		/* ############# Constructor/destructor ############# */ 
		function __construct($id){
			$this->cache = array();
			$this->data = array();
			$this->loadRoutine;
			$this->id = intval($id);

			$this->relations = array();
		}
		function  __destruct(){
			$this->save();
		}
		private function setterId($value){
			self::setReadOnlyAttributeException();
		}
		private function getterId($value){
			return $this->id;
		}
		public static function defineDatabase($db){
			static::$db = $db;
		}



		/* ############# Create a new entry in db ############# */ 
		public static function create($data){
			$argsTab =[];
			$argsTabRef =[];
			$argsKeys = [];
			$fields = [];
			$type = '';
			$cclass = get_called_class();

			foreach ($data as $key => $value) {
				$nameConstraint = 'creationIsValid'.ucfirst($key);
				if  (
						(array_search($key, $cclass::$keys)===false) ||
						(
							method_exists($cclass, $nameConstraint)
							&&
							!$cclass::$nameConstraint($key, $value)
						)
					){ 
						unset($data[$key]);
						continue;
				}
				$valueFinal = $value;
				//Todo here
				// $c = $this->callCustomHandler('castOnCreation', $key, $value);
				// if($c['defined'])
				// 	$valueFinal = $data[$key] = $c['result'];
				$type.='s';
				$argsTab[] = $valueFinal;
				$argsTabRef[] = &$data[$key];
				$argsKeys[] = $key;
				$fields[] = '?';
			}
			$fields = implode(',', $fields);

			if(property_exists(get_called_class(), 'requiredFieldsOnCreation'))
				for($i=0;$i<count(static::$requiredFieldsOnCreation); $i++){
					$key = static::$requiredFieldsOnCreation[$i];
					if(!array_key_exists($key, $argsKeys) || !$argsKeys[$key])
						throw new Exception("Error Processing Request", 1);
				}

			$request = static::$db->prepare('INSERT INTO Projets ('.implode(',', $argsKeys).') VALUES ('.$fields.')');
			call_user_func_array([$request, 'bind_param'], array_merge(array($type), $argsTabRef));
			$request->execute();

			if(!static::$db)
				throw new Exception(mysqli_error(static::$db));

			if($request->error)
				throw new Exception($request->error);

			return new Projet(static::$db->insert_id);
		}




		/* ############# Cache related functions ############# */ 
		public function load($force = false){
			if($this->isLoaded && !$force)
				throw new Exception("Already loaded");
			$res = static::$db->query('SELECT * FROM '.static::$tableName.' WHERE id='.intval($this->id)) or die(mysqli_error(static::$db));
			if(!$res) throw new Exception("Error Processing Request");
			$res = $res->fetch_assoc();
			if(!$res) throw new Exception("Error Processing Request");

			if(is_function(@$this->loadRoutine['@remplace']))
				$this->loadRoutine['@remplace']($res->fetch_assoc());
			else{
				foreach ($res as $key => $value)
					if(@$this->loadRoutine[$key])
						$this->data[$key] = $this->loadRoutine[$key];
					elseif(in_array($key, static::$keys))
						$this->data[$key] = $value;
					elseif($key!='id')
						throw new Exception("Err key".$key);
			}
			if(is_function(@$this->loadRoutine['@after']))
				$this->loadRoutine['@after']();

			$this->forceLoadRelationsData();

			$this->isLoaded = true;
		}
		public function buildFromData($data){
			if($this->isLoaded)
				throw new Exception("Loaded already");
			foreach (static::$keys as $k)
				$this->data[$k] = @$data[$k];
			$this->forceLoadRelationsData();
			$this->isLoaded = true;
		}
		public function save(){
			if(count(array_keys($this->cache))==0)
				return;
			$this->db->query('UPDATE '.static::$tableName.' SET '.join(',', array_map(function($o){
				return $o.'="'.$this->db->real_escape_string($this->cache[$o]).'"';
			}, array_keys($this->cache))).' WHERE id='.intval($this->id));
			$this->resetCache();
		}
		private function resetCache(){
			foreach ($this->cache as $key => $value)
				unset($this->cache[$key]);
		}





		/* ############# Relations managment ############# */
		private function doesRelationExists($relationName){
			if(!static::$relationsDescription)
				return false;
			return array_search(
				$relationName,
				array_map(
					function($o) use ($relationName){
						return $o['name']==$relationName;
					},
					static::$relationsDescription
				)
			)!==false;
		}
		private function getAutoloadRelations(){
			return array_filter(static::$relationsDescription, function($o){
				return @$o['autoload'];
			});
		}
		private function forceLoadRelationsData(){
			if(!static::$relationsDescription)
				return false;
			$relationsAutoLoad = $this->getAutoloadRelations();
			foreach ($relationsAutoLoad as $r)
				$this->getRelation($r['name']);
		}
		private function getRelationDescriptor($relationName){
			$result = array_filter(static::$relationsDescription, function($o) use ($relationName){
				return $o['name']==$relationName;
			});
			return array_pop($result);
		}
		public function getRelationsInCache(){
			return array_keys($this->relations);
		}
		public function getRelation($relationName, $refresh = false){
			if(!$this->doesRelationExists($relationName))
				throw new Exception("Error Processing Request", 1);
			
			$relation = $this->getRelationDescriptor($relationName);

			if(!array_key_exists($relationName, $this->relations) || $refresh){
				$this->relations[$relationName] = array();
				$sortPart = '';
				if(@$relation['sort'])
					$sortPart = ' ORDER BY '.$relation['sort'];
				$idsLst = LittlePAPI::_makeFreeSQLOneRow(
						'SELECT `'.$relation['externId'].'` FROM `'.$relation['tableName'].'` WHERE `'.$relation['internId'].'`='.$this->id.$sortPart,
						$relation['externId'],
						true
					);
				if($relation['classObject']===false)
					$this->relations[$relationName]['cache'] = $idsLst;
				else{
					if(count($idsLst))
						$this->relations[$relationName]['cache'] = $relation['classObject']::_fetchAll(' WHERE id IN ('.implode(', ', $idsLst).')');
					else
						$this->relations[$relationName]['cache'] = [];
				}
			}

			return $this->relations[$relationName]['cache'];
		}
		public function addRelation($relationName, $id){
			if(!$this->doesRelationExists($relationName))
				throw new Exception("Error Processing Request", 1);
		
			$relation = $this->getRelationDescriptor($relationName);

			LittlePAPI::_makeFreeCustomSQLRequest(
				'INSERT IGNORE INTO `'.$relation['tableName'].
				'` (`'.$relation['externId'].'`, `'.$relation['internId'].'`) VALUES'.
				'('.intval($id).','.$this->get('id').')'
			);

			$this->getRelation($relationName, true);//Refresh data
		}
		public function removeRelation($relationName, $id){
			if(!$this->doesRelationExists($relationName))
				throw new Exception("Error Processing Request", 1);
		
			$relation = $this->getRelationDescriptor($relationName);

			LittlePAPI::_makeFreeCustomSQLRequest(
				'DELETE FROM `'.$relation['tableName'].'` WHERE `'.$relation['internId'].'`='.$this->get('id').
				' AND `'.$relation['externId'].'`='.intval($id)
			);

			$this->getRelation($relationName, true);//Refresh data
		}



		public static function RegisterRelation($tableName, $A, $B){
			/*
				tableName
					'RelAvailabilitySessionsSupervisors',
				A
					'name' => 'supervisorsAvailable',
					'id' => 'idUtilisateur',
					'className' => 'Utilisateur',
					['autoload'  => true/false]
				B
					'name' => 'supervisorsAvailable',
					'id' => 'idSession',
					'className' => 'Session',
					['autoload'  => true/false]
			*/

			if(@$A['autoload'] && @$A['autoload'])
				throw new Exception("RegisterRelation : A and B can't autload each other (make circular reference for JSON)", 1);

			$add = function($A, $B) use ($tableName){
				$A['className']::$relationsDescription[] = [
					'name' => $A['name'],
					'tableName' => $tableName,
					'internId' => $A['id'],
					'externId' => $B['id'],
					'classObject' => $B['className'],
					'autoload' => (@$A['autoload']==true)
				];
			};

			$add($A, $B);
			$add($B, $A);
		}



		/* ############# Constraint manager ############# */
		public function callCustomHandler($constraintPrefix, $keyName, $value){
			$constraintName = $constraintPrefix . ucfirst($keyName);
			if(method_exists($this, $constraintName))
				return ['defined' => true, 'result' => $this->$constraintName($value)];
			return ['defined' => false, 'result' => true];
		}


		/* ############# Accessors methods ############# */
		public function set($key, $value){
			$this->callCustomHandler('beforeSet', $key, $value);
			if  (
					!$this->callCustomHandler('constraintSet',	$key, $value)['result']  ||
					 $this->callCustomHandler('setter',			$key, $value)['defined']
				)
					return false;

			if($this->doesRelationExists($key)){
				$id = $value;
				if(is_object($id))	//if given value is an object (we assume it's an LittlePAPI-derivated object)
					$id = $id->get('id');
				return $this->addRelation($key, intval($id));
			}

			//If key is not defined (in the keys attr of the sub class)
			if(!in_array($key, static::$keys))
				throw new Exception("Key \"".$key."\" doesn't exists");

			$this->cache[$key] = $value;

			$this->callCustomHandler('afterSet', $key, $value);
		}
		public function get($key){
			if(!$this->isLoaded)
				$this->load();

			$this->callCustomHandler('beforeGet', $key, null);
			if  (
					!$this->callCustomHandler('constraintGet',	$key, null)['result']  ||
					 $this->callCustomHandler('getter',			$key, null)['defined']
				)
					return false;

			$value = null;
			if($this->doesRelationExists($key))
				$value = $this->getRelation($key);
			else if(!in_array($key, static::$keys))
				throw new Exception("Key \"".$key."\" doesn't exists");
			else if(array_key_exists($key, $this->cache))
				$value = $this->cache[$key];
			else
				$value = $this->data[$key];

			$r = $this->callCustomHandler('transformGet', $key, $value);
			if($r['defined'])
				return $r['result'];
			return $value;
		}




		/* ############# Other methods ############# */ 
		public static function setReadOnlyAttributeException($name){
			throw new Exception('Try to set read-only attribute "'.$name.'"', 1);
		}


		
		/* ############# SQL ############# */ 
		public static function _makeFreeCustomSQLRequest($sql){
			$result = static::$db->query($sql) or die(mysqli_error(static::$db));
			if(is_object($result)){
				$tab = array();
				while($line = $result->fetch_assoc())
					$tab[] = $line;
				return $tab;
			}else{
				return $result;
			}
		}
		public static function _makeFreeSQLOneRow($sql, $rowName, $doIntval = false){
			return array_map(function($row) use ($rowName, $doIntval){
				$v = $row[$rowName];
				return $doIntval ? intval($v) : $v; 
			}, LittlePAPI::_makeFreeCustomSQLRequest($sql));
		}
		public static function _makeCustomSQLRequest($sql, $idField){
			$class = get_called_class();
			$result = static::$db->query($sql) or die(mysqli_error(static::$db));
			$tab = array();
			while($line = $result->fetch_assoc()){
				$o = new $class($line[$idField]);
				$o->buildFromData($line);
				$tab[] = $o;
			}
			return $tab;
		}
		public static function _fetchAll($endClause = '', $loadAttrs = []){
			$class = get_called_class();

			if(method_exists($class, 'constraintFetchAll')){
				if(!static::constraintFetchAll())
					return false;
			}

			$result = static::$db->query('SELECT * FROM '.static::$tableName.' '.$endClause) or die(mysqli_error(static::$db));
			$tab = array();
			while($line = $result->fetch_assoc()){
				$o = new $class($line['id']);
				$o->buildFromData($line);
				foreach ($loadAttrs as $attr)
					$o->get($attr);
				$tab[] = $o;
			}
			return $tab;
		}


		/* ############# JSON matters ############# */ 
		public function jsonSerialize(){
			return $this::_getLeanObject($this);
		}
		public static function _getLeanObject($o){
			$obj = array();

			$addSpecKey = function($key, $givenValue) use (&$obj, $o){
				//constraintSerializeKey must return ["show"=>Bool, "content"=>TransformedValue]
				$c = $o->callCustomHandler('constraintSerialize', $key, null);
				if($c['defined']){
					$res = $c['result']($key, $givenValue);
					if($res['show']===false)
						$obj[$key] = $res['content'];
				}else
					$obj[$key] = $givenValue;
			};

			foreach ($o::$keys as $key)
				$addSpecKey($key, $o->get($key));
			foreach ($o->getRelationsInCache() as $key)
				$addSpecKey($key, $o->get($key));
			$addSpecKey('id', $o->id);

			return $obj;
		}




		/* ############# API Bindings ############# */ 
		private static function _followApiVerb($class, $getMethodsFactory, $prefix, $getParams, $data, $acceptNoId = false){
			$getMethodsF = $getMethodsFactory($prefix);

			if(@$getParams[0]=='@userId' && getUserType()>ANONYME)
				$getParams[0] = getUserId();

			if(isset($getParams[0]) && (intval($getParams[0]).'')==$getParams[0]){//If fixed id
				$getMethods = $getMethodsF($class);
				$o = new $class(intval($getParams[0]));

				if($o==null)
					return [false, false];

				$action = @$getParams[1];
				if(!$action)
					return [true, $o];
				else{
					if(in_array($action, $getMethods)){
						$methodName = $prefix . strtoupper(substr($action, 0, 1)) . substr($action, 1);
						return [true, $o->$methodName(array_slice($getParams, 2), $data)];
					}else{
						return [false, $o, $action, array_slice($getParams, 2)];
					}
				}
			}elseif($acceptNoId){
				$getMethods = $getMethodsF($class.'s');

				$action = @$getParams[0];
				if(!$action)
					$action = 'all';
				if(in_array($action, $getMethods)){
					$methodName = $prefix . strtoupper(substr($action, 0, 1)) . substr($action, 1);
					$d = forward_static_call_array(array($class.'s', $methodName), array_merge(array_slice($getParams, 1), [$data]));
					
					return [true, $d];
				}elseif(isset(static::$bindApiGet)){
					$f = static::$bindApiGet;
					return [true, $f($getParams, $data)];
				}
			}else{
				return [false, false];
			}
		}
		public static function _bindApiGet($getParams, $postParams, $verb){
			$class = get_called_class();
			$getMethodsF = function($prefix){
				return function($className) use ($prefix){
					return array_map(
						function($fName) use ($prefix){
							$fName = substr($fName, strlen($prefix));
							return strtolower(substr($fName, 0, 1)) . substr($fName, 1);
						}, array_filter(
							get_class_methods($className),
							function($fName) use ($prefix){
								return substr($fName, 0, strlen($prefix))==$prefix;
							}
						)
					);
				};
			};
			if($verb=='GET'){
				$result = self::_followApiVerb($class, $getMethodsF, 'get', $getParams, $postParams, true);
				if($result[0]===true)
					return $result[1];
				elseif($result[0]===false && $result[1]!==false){
					$v = false;
					try{
						$v = $result[1]->get($result[2]);
					}catch(Exception $e){}
					return $v;
				}else
					return false;
			}elseif($verb=='PUT'){
				$result = self::_followApiVerb($class, $getMethodsF, 'put', $getParams, $postParams, false);
				if($result[0]===true)
					return $result[1];
				elseif($result[1]!==false){
					if($result[2]=='POST'){
						foreach ($postParams as $key => $value)
							if($key!='id')
								$result[1]->set($key, $value);
					}else{
						$result[1]->set($result[2], $result[3][0]);
					}
					return true;
				}else
					return false;
			}elseif($verb=='POST'){
				$result = self::_followApiVerb($class, $getMethodsF, 'post', $getParams, $postParams, false);
				if($result[0]===true)
					return $result[1];
				
				forward_static_call_array(array($class, 'create'), [$postParams]);
				return true;
			}elseif($verb=='DELETE'){
				$result = self::_followApiVerb($class, $getMethodsF, 'delete', $getParams, $postParams, false);
				if($result[0]===true)
					return $result[1];
				elseif($result[1]!==false){
					if($result[2]=='entire-object'){

						$tn = $class::$tableName;
						LittlePAPI::_makeFreeCustomSQLRequest('DELETE FROM '.$tn.' WHERE id='.$result[1]->get('id'));
						return true;
					}elseif($result[1]->doesRelationExists($result[2])){
						$result[1]->removeRelation($result[2], intval($result[3][0]));
						return true;
					}
					return false;
				}else
					return false;
			}
		}
		public static function APIHandler(){
			header('Content-type: application/json');

			preg_match_all("/^.*\?|\/?([^\/]*)|/", $_SERVER['REQUEST_URI'], $parsedParam);
			array_shift($parsedParam[1]);
			while(count($parsedParam[1]) && $parsedParam[1][count($parsedParam[1])-1]=="")
				array_pop($parsedParam[1]);
			$urlParams = $parsedParam[1];

			$route = [];
			foreach(get_declared_classes() as $class)
		        if(is_subclass_of($class,'LittlePAPI')){
		        	if(property_exists($class, 'customAPIName'))
		        		$route[$class::$customAPIName] = $class;
		        	else
			        	$route[strtolower($class)] = $class;
		        }

			$POST = json_decode(file_get_contents('php://input'), true);
			$d = null;

			if($urlParams==0 || !isset($route[$urlParams[0]]))
				$d = 'error';
			else{
				$class = $route[array_shift($urlParams)];
				$d = $class::_bindApiGet($urlParams, $POST, $_SERVER['REQUEST_METHOD']);
			}
			
			echo json_encode($d);

			static::$db = null;
			exit();
		}
	}
?>