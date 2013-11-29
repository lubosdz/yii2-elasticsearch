<?php
/**
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace yii\redis;

use yii\base\InvalidConfigException;
use yii\base\NotSupportedException;
use yii\helpers\StringHelper;

/**
 * ActiveRecord is the base class for classes representing relational data in terms of objects.
 *
 * This class implements the ActiveRecord pattern for the [redis](http://redis.io/) key-value store.
 *
 * For defining a record a subclass should at least implement the [[attributes()]] method to define
 * attributes. A primary key can be defined via [[primaryKey()]] which defaults to `id` if not specified.
 *
 * The following is an example model called `Customer`:
 *
 * ```php
 * class Customer extends \yii\redis\ActiveRecord
 * {
 *     public function attributes()
 *     {
 *         return ['id', 'name', 'address', 'registration_date'];
 *     }
 * }
 * ```
 *
 * @author Carsten Brandt <mail@cebe.cc>
 * @since 2.0
 */
class ActiveRecord extends \yii\db\ActiveRecord
{
	/**
	 * Returns the database connection used by this AR class.
	 * By default, the "redis" application component is used as the database connection.
	 * You may override this method if you want to use a different database connection.
	 * @return Connection the database connection used by this AR class.
	 */
	public static function getDb()
	{
		return \Yii::$app->getComponent('redis');
	}

	/**
	 * @inheritdoc
	 */
	public static function createQuery()
	{
		return new ActiveQuery(['modelClass' => get_called_class()]);
	}

	/**
	 * @inheritdoc
	 */
	public static function createActiveRelation($config = [])
	{
		return new ActiveRelation($config);
	}

	/**
	 * Returns the primary key name(s) for this AR class.
	 * This method should be overridden by child classes to define the primary key.
	 *
	 * Note that an array should be returned even when it is a single primary key.
	 *
	 * @return string[] the primary keys of this record.
	 */
	public static function primaryKey()
	{
		return ['id'];
	}

	/**
	 * Returns the list of all attribute names of the model.
	 * This method must be overridden by child classes to define available attributes.
	 * @return array list of attribute names.
	 */
	public function attributes()
	{
		throw new InvalidConfigException('The attributes() method of redis ActiveRecord has to be implemented by child classes.');
	}

	/**
	 * @inheritdoc
	 */
	public function insert($runValidation = true, $attributes = null)
	{
		if ($runValidation && !$this->validate($attributes)) {
			return false;
		}
		if ($this->beforeSave(true)) {
			$db = static::getDb();
			$values = $this->getDirtyAttributes($attributes);
			$pk = [];
//			if ($values === []) {
			foreach ($this->primaryKey() as $key) {
				$pk[$key] = $values[$key] = $this->getAttribute($key);
				if ($pk[$key] === null) {
					$pk[$key] = $values[$key] = $db->executeCommand('INCR', [static::tableName() . ':s:' . $key]);
					$this->setAttribute($key, $values[$key]);
				}
			}
//			}
			// save pk in a findall pool
			$db->executeCommand('RPUSH', [static::tableName(), static::buildKey($pk)]);

			$key = static::tableName() . ':a:' . static::buildKey($pk);
			// save attributes
			$args = [$key];
			foreach($values as $attribute => $value) {
				$args[] = $attribute;
				$args[] = $value;
			}
			$db->executeCommand('HMSET', $args);

			$this->setOldAttributes($values);
			$this->afterSave(true);
			return true;
		}
		return false;
	}

	/**
	 * Updates the whole table using the provided attribute values and conditions.
	 * For example, to change the status to be 1 for all customers whose status is 2:
	 *
	 * ~~~
	 * Customer::updateAll(['status' => 1], ['id' => 2]);
	 * ~~~
	 *
	 * @param array $attributes attribute values (name-value pairs) to be saved into the table
	 * @param array $condition the conditions that will be put in the WHERE part of the UPDATE SQL.
	 * Please refer to [[ActiveQuery::where()]] on how to specify this parameter.
	 * @param array $params this parameter is ignored in redis implementation.
	 * @return integer the number of rows updated
	 */
	public static function updateAll($attributes, $condition = null, $params = [])
	{
		if (empty($attributes)) {
			return 0;
		}
		$db = static::getDb();
		$n=0;
		foreach(static::fetchPks($condition) as $pk) {
			$newPk = $pk;
			$pk = static::buildKey($pk);
			$key = static::tableName() . ':a:' . $pk;
			// save attributes
			$args = [$key];
			foreach($attributes as $attribute => $value) {
				if (isset($newPk[$attribute])) {
					$newPk[$attribute] = $value;
				}
				$args[] = $attribute;
				$args[] = $value;
			}
			$newPk = static::buildKey($newPk);
			$newKey = static::tableName() . ':a:' . $newPk;
			// rename index if pk changed
			if ($newPk != $pk) {
				$db->executeCommand('MULTI');
				$db->executeCommand('HMSET', $args);
				$db->executeCommand('LINSERT', [static::tableName(), 'AFTER', $pk, $newPk]);
				$db->executeCommand('LREM', [static::tableName(), 0, $pk]);
				$db->executeCommand('RENAME', [$key, $newKey]);
				$db->executeCommand('EXEC');
			} else {
				$db->executeCommand('HMSET', $args);
			}
			$n++;
		}
		return $n;
	}

	/**
	 * Updates the whole table using the provided counter changes and conditions.
	 * For example, to increment all customers' age by 1,
	 *
	 * ~~~
	 * Customer::updateAllCounters(['age' => 1]);
	 * ~~~
	 *
	 * @param array $counters the counters to be updated (attribute name => increment value).
	 * Use negative values if you want to decrement the counters.
	 * @param array $condition the conditions that will be put in the WHERE part of the UPDATE SQL.
	 * Please refer to [[ActiveQuery::where()]] on how to specify this parameter.
	 * @param array $params this parameter is ignored in redis implementation.
	 * @return integer the number of rows updated
	 */
	public static function updateAllCounters($counters, $condition = null, $params = [])
	{
		if (empty($counters)) {
			return 0;
		}
		$db = static::getDb();
		$n=0;
		foreach(static::fetchPks($condition) as $pk) {
			$key = static::tableName() . ':a:' . static::buildKey($pk);
			foreach($counters as $attribute => $value) {
				$db->executeCommand('HINCRBY', [$key, $attribute, $value]);
			}
			$n++;
		}
		return $n;
	}

	/**
	 * Deletes rows in the table using the provided conditions.
	 * WARNING: If you do not specify any condition, this method will delete ALL rows in the table.
	 *
	 * For example, to delete all customers whose status is 3:
	 *
	 * ~~~
	 * Customer::deleteAll(['status' => 3]);
	 * ~~~
	 *
	 * @param array $condition the conditions that will be put in the WHERE part of the DELETE SQL.
	 * Please refer to [[ActiveQuery::where()]] on how to specify this parameter.
	 * @param array $params this parameter is ignored in redis implementation.
	 * @return integer the number of rows deleted
	 */
	public static function deleteAll($condition = null, $params = [])
	{
		$db = static::getDb();
		$attributeKeys = [];
		$pks = static::fetchPks($condition);
		$db->executeCommand('MULTI');
		foreach($pks as $pk) {
			$pk = static::buildKey($pk);
			$db->executeCommand('LREM', [static::tableName(), 0, $pk]);
			$attributeKeys[] = static::tableName() . ':a:' . $pk;
		}
		if (empty($attributeKeys)) {
			$db->executeCommand('EXEC');
			return 0;
		}
		$db->executeCommand('DEL', $attributeKeys);
		$result = $db->executeCommand('EXEC');
		return end($result);
	}

	private static function fetchPks($condition)
	{
		$query = static::createQuery();
		$query->where($condition);
		$records = $query->asArray()->all(); // TODO limit fetched columns to pk
		$primaryKey = static::primaryKey();

		$pks = [];
		foreach($records as $record) {
			$pk = [];
			foreach($primaryKey as $key) {
				$pk[$key] = $record[$key];
			}
			$pks[] = $pk;
		}
		return $pks;
	}

	/**
	 * Builds a normalized key from a given primary key value.
	 *
	 * @param mixed $key the key to be normalized
	 * @return string the generated key
	 */
	public static function buildKey($key)
	{
		if (is_numeric($key)) {
			return $key;
		} elseif (is_string($key)) {
			return ctype_alnum($key) && StringHelper::strlen($key) <= 32 ? $key : md5($key);
		} elseif (is_array($key)) {
			if (count($key) == 1) {
				return self::buildKey(reset($key));
			}
			ksort($key); // ensure order is always the same
			$isNumeric = true;
			foreach($key as $value) {
				if (!is_numeric($value)) {
					$isNumeric = false;
				}
			}
			if ($isNumeric) {
				return implode('-', $key);
			}
		}
		return md5(json_encode($key));
	}

	/**
	 * @inheritdoc
	 */
	public static function getTableSchema()
	{
		throw new NotSupportedException('getTableSchema() is not supported by redis ActiveRecord.');
	}

	/**
	 * @inheritdoc
	 */
	public static function findBySql($sql, $params = [])
	{
		throw new NotSupportedException('findBySql() is not supported by redis ActiveRecord.');
	}

	/**
	 * Returns a value indicating whether the specified operation is transactional in the current [[scenario]].
	 * This method will always return false as transactional operations are not supported by redis.
	 * @param integer $operation the operation to check. Possible values are [[OP_INSERT]], [[OP_UPDATE]] and [[OP_DELETE]].
	 * @return boolean whether the specified operation is transactional in the current [[scenario]].
	 */
	public function isTransactional($operation)
	{
		return false;
	}
}