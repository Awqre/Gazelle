<?
class G {
	/** @var DB_MYSQL */
	public static $DB;
	/** @var CACHE */
	public static $Cache;
	/** @var \Gazelle\Router */
	public static $Router;

	public static $LoggedUser;

	public static function initialize() {
		global $DB, $Cache, $LoggedUser;
		self::$DB = $DB;
		self::$Cache = $Cache;
		self::$LoggedUser =& $LoggedUser;
	}
}