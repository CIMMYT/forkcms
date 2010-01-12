<?php

// @todo tijs - passwords moeten een salt gebruiken. Graag hier dan een functie getGeneratedPassword($string)

/**
 * BackendAuthentication
 *
 * The class below will handle all authentication stuff. It will handle module-access, action-acces, ...
 *
 * @package		backend
 * @subpackage	authentication
 *
 * @author 		Tijs Verkoyen <tijs@netlash.com>
 * @author		Davy Hellemans <davy@netlash.com>
 * @since		2.0
 */
class BackendAuthentication
{
	/**
	 * All allowed modules
	 *
	 * @var	array
	 */
	private static $allowedActions = array();


	/**
	 * All allowed modules
	 *
	 * @var	array
	 */
	private static $allowedModules = array();


	/**
	 * A userobject for the current authenticated user
	 *
	 * @var	BackendUser
	 */
	private static $user;


	/**
	 * Cleanup sessions for the current user and sessions that are invalid
	 *
	 * @return	void
	 */
	public static function cleanupOldSessions()
	{
		// init var
		$db = BackendModel::getDB();

		// remove all sessions that are invalid (older then 30min)
		$db->delete('users_sessions', 'date <= DATE_SUB(NOW(), INTERVAL 30 MINUTE)');
	}


	/**
	 * Returns the current authenticated user
	 *
	 * @return	BackendUser
	 */
	public static function getUser()
	{
		// if the user-object doesn't exist create a new one
		if(self::$user === null) self::$user = new BackendUser();

		// return the object
		return self::$user;
	}


	/**
	 * Is the given action allowed for the current user
	 *
	 * @return	bool
	 * @param	string $action
	 * @param	string $module
	 */
	public static function isAllowedAction($action, $module)
	{
		// always allowed actions (yep, hardcoded, because we don't want other people to fuck up)
		$alwaysAllowed = array(	'dashboard' => array('index' => 7),
								'error' => array('index' => 7),
								'authentication' => array('index' => 7, 'logout' => 7));

		// redefine
		$action = (string) $action;
		$module = (string) $module;

		// is this action an action that doesn't require authentication?
		if(isset($alwaysAllowed[$module][$action])) return true;

		// we will cache everything
		if(empty(self::$allowedActions))
		{
			// init var
			$db = BackendModel::getDB();

			// get active modules
			$activeModules = (array) $db->getColumn('SELECT m.name
														FROM modules AS m
														WHERE m.active = ?;',
														array('Y'));

			// add always allowed
			foreach($alwaysAllowed as $allowedModule => $actions) $activeModules[] = $allowedModule;

			// get allowed actions
			$allowedActionsRows = (array) $db->retrieve('SELECT gra.module, gra.action, gra.level
														FROM users_sessions AS us
														INNER JOIN users AS u ON us.user_id = u.id
														INNER JOIN groups_rights_actions AS gra ON u.group_id = gra.group_id
														WHERE us.session_id = ? AND us.secret_key = ?;',
														array(SpoonSession::getSessionId(), SpoonSession::get('backend_secret_key')));

			// add all actions and there level
			foreach($allowedActionsRows as $row)
			{
				// add if the module is active
				if(in_array($row['module'], $activeModules)) self::$allowedActions[$row['module']][$row['action']] = (int) $row['level'];
			}
		}

		// do we know a level for this action
		if(isset(self::$allowedActions[$module][$action]))
		{
			// is the level greater than zero? aka: do we have access?
			if((int) self::$allowedActions[$module][$action] > 0) return true;
		}

		// fallback
		return false;
	}


	/**
	 * Is the given module allowed for the current user
	 *
	 * @return	bool
	 * @param	string $module
	 */
	public static function isAllowedModule($module)
	{
		// always allowed modules (yep, hardcoded, because, we don't want other people to fuck up)
		$alwaysAllowed = array('error', 'authentication');

		// redefine
		$module = (string) $module;

		// is this module a module that doesn't require authentication?
		if(in_array($module, $alwaysAllowed)) return true;

		// do we already know something?
		if(empty(self::$allowedModules))
		{
			// init var
			$db = BackendModel::getDB();

			// get allowed modules
			$allowedModules = $db->getColumn('SELECT grm.module
												FROM users_sessions AS us
												INNER JOIN users AS u ON us.user_id = u.id
												INNER JOIN groups_rights_modules AS grm ON u.group_id = grm.group_id
												WHERE us.session_id = ? AND us.secret_key = ?;',
												array(SpoonSession::getSessionId(), SpoonSession::get('backend_secret_key')));

			// add all modules
			foreach($allowedModules as $row) self::$allowedModules[$row] = true;
		}

		// not available in our cache
		if(!isset(self::$allowedModules[$module])) return false;

		// return value that was stored in cache
		else return self::$allowedModules[$module];
	}


	/**
	 * Is the current user logged in?
	 *
	 * @return	bool
	 */
	public static function isLoggedIn()
	{
		// check if all needed values are set in the session
		if(SpoonSession::exists('backend_logged_in', 'backend_secret_key') && (bool) SpoonSession::get('backend_logged_in') && (string) SpoonSession::get('backend_secret_key') != '')
		{
			// get database instance
			$db = BackendModel::getDB();

			// get the row from the tables
			$sessionData = $db->getRecord('SELECT us.id, us.user_id
											FROM users_sessions AS us
											WHERE us.session_id = ? AND us.secret_key = ?
											LIMIT 1;',
											array(SpoonSession::getSessionId(), SpoonSession::get('backend_secret_key')));

			// if we found a matching row, we know the user is logged in, so we update his session
			if($sessionData !== null)
			{
				// update the session in the table
				$db->update('users_sessions', array('date' => date('Y-m-d H:i:s'), 'language' => BackendLanguage::getWorkingLanguage()), 'id = ?', (int) $sessionData['id']);

				// create a user object, it will handle stuff related to the current authenticated user
				self::$user = new BackendUser($sessionData['user_id']);

				// the user is logged on
				return true;
			}

			// no data found, so fuck up the session, will be handled later on in the code
			else SpoonSession::set('backend_logged_in', false);
		}

		// no data found, so fuck up the session, will be handled later on in the code
		else SpoonSession::set('backend_logged_in', false);

		// reset values for invalid users. We can't destroy the session because session-data can be used on the site.
		if((bool) SpoonSession::get('backend_logged_in') === false)
		{
			// reset some values
			SpoonSession::set('backend_logged_in', false);
			SpoonSession::set('backend_secret_key', '');

			// return result
			return false;
		}
	}


	/**
	 * Logsout the current user
	 *
	 * @return	void
	 */
	public static function logout()
	{
		// init var
		$db = BackendModel::getDB();

		// remove all rows owned by the current user
		$db->delete('users_sessions', 'session_id = ?', SpoonSession::getSessionId());

		// reset values. We can't destroy the session because session-data can be used on the site.
		SpoonSession::set('backend_logged_in', false);
		SpoonSession::set('backend_secret_key', '');
	}


	/**
	 * Login the user with the given credentials.
	 * Will return a boolean that indicates if the user is logged in.
	 *
	 * @return	bool
	 * @param	string $login
	 * @param	string $password
	 */
	public static function loginUser($login, $password)
	{
		// redefine
		$login = (string) $login;
		$password = (string) $password;

		// init vars
		$db = BackendModel::getDB();

		// check in database (is the user active and not deleted, are the username and password correct?)
		$userId = (int) $db->getVar('SELECT u.id
										FROM users AS u
										WHERE u.username = ? AND u.password = ? AND u.active = ? AND u.deleted = ?
										LIMIT 1;',
										array($login, md5($password), 'Y', 'N'));

		// not 0, a valid user!
		if($userId !== 0)
		{
			// cleanup old sessions
			self::cleanupOldSessions();

			// build the session array (will be stored in the database)
			$session = array();
			$session['user_id'] = $userId;
			$session['language'] = BackendLanguage::getWorkingLanguage();
			$session['secret_key'] = md5(md5($userId) . md5(SpoonSession::getSessionId()));
			$session['session_id'] = SpoonSession::getSessionId();
			$session['date'] = date('Y-m-d H:i:s');

			// insert a new row in the session-table
			$db->insert('users_sessions', $session);

			// store some values in the session
			SpoonSession::set('backend_logged_in', true);
			SpoonSession::set('backend_secret_key', $session['secret_key']);

			// return result
			return true;
		}

		// userId 0 will not exist, so it means that this isn't a valid combination
		else
		{
			// reset values for invalid users. We can't destroy the session because session-data can be used on the site.
			SpoonSession::set('backend_logged_in', false);
			SpoonSession::set('backend_secret_key', '');

			// return result
			return false;
		}
	}
}

?>