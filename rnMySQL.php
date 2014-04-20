<?php

assert(defined('MYSQL_HOST'));
assert(defined('MYSQL_PORT'));
assert(defined('MYSQL_USER'));
assert(defined('MYSQL_PASSWORD'));
assert(defined('MYSQL_DB_NAME'));

class MySQLConnector {
  private static $con = null;

  public static function get() {
    if (is_resource(self::$con)) {
      return self::$con;
    }

    self::$con = mysql_connect(MYSQL_HOST . ':' . MYSQL_PORT, MYSQL_USER, MYSQL_PASSWORD);
    mysql_select_db(MYSQL_DB_NAME, self::$con);
    mysql_set_charset("utf8mb4");
    return self::$con;
  }
}


class MySQLTable {
  static protected $name = null;

  public static function truncate() {
    $sql = 'TRUNCATE ' . static::$name;
    $con = MySQLConnector::get();
    $result = mysql_query($sql, $con);
    return $result;
  }
  
  public static function count($where) {
    $sql = 'SELECT COUNT(*) FROM ' . static::$name . ' WHERE ' . self::parseWhere($where);
    return self::countBySql($sql);
  }

  public static function countBySql($sql) {
    assert(is_string($sql));
    $con = MySQLConnector::get();
    $result = mysql_query($sql, $con);
    assert(is_resource($result));
    $tmp = mysql_fetch_row($result);
    $ret = $tmp[0];
    assert(is_numeric($ret));
    return $ret;
  }

  public static function delete($where) {
    $sql = 'DELETE FROM ' . static::$name . ' WHERE ' . self::parseWhere($where);
    $con = MySQLConnector::get();
    $result = mysql_query($sql, $con);
    return $result;
  }

  public static function find($id) {
    assert(is_numeric($id));
    return self::selectFirst(array('id' => $id));
  }

  public static function insert($record) {
    assert(is_hasharray($record));
    $keys = array_keys($record);
    $sql = 'INSERT INTO ' . static::$name . '(';
    for ($i = 0; $i < count($keys); $i++) {
      $k = $keys[$i];
      assert(is_word($k));
      if ($i) {
        $sql .= ', ';
      }
      $sql .= '`' . $k . '`';
    }
    $sql .= ') VALUES (';
    for ($i = 0; $i < count($keys); $i++) {
      $k = $keys[$i];
      assert(is_word($k));
      if ($i) {
        $sql .= ', ';
      }
      $sql .= self::escape($record[$k]);
    }
    $sql .= ')';
    $con = MySQLConnector::get();
    $result = mysql_query($sql, $con);
    if ($result) {
      return mysql_insert_id($con);
    }
    return false;
  }

  public static function selectAll($where = '1', $order = null, $offset = null, $limit = null) {
    $sql = 'SELECT * FROM ' . static::$name . ' WHERE ' . self::parseWhere($where);
    if (! is_null($order)) {
      assert(is_string($order));
      $sql .= ' ORDER BY ' . $order;
    }
    if (is_null($offset) && ! is_null($limit)) {
      assert(is_numeric($limit));
      $sql .= ' LIMIT 0, ' . $limit;
    } elseif (! is_null($offset) && ! is_null($limit)) {
      assert(is_numeric($offset));
      assert(is_numeric($limit));
      $sql .= " LIMIT $offset, $limit";
    }
    return self::selectAllBySql($sql);
  }

  public static function selectAllBySql($sql) {
    is_string($sql);
    $con = MySQLConnector::get();
    $result = mysql_query($sql, $con);
    assert(is_resource($result));
    return mysql_fetch_all($result);
  }

  public static function selectFirst($where, $order = null) {
    $con = MySQLConnector::get();
    $sql = "SELECT * FROM " . static::$name . " WHERE " . self::parseWhere($where);
    if (! is_null($order)) {
      assert(is_string($order));
      $sql .= ' ORDER BY ' . $order;
    }
    $sql .= ' LIMIT 0, 1';
    $result = mysql_query($sql, $con);
    assert(is_resource($result));
    return mysql_fetch_assoc($result);
  }

  public static function update($updates, $where) {
    assert(is_hasharray($updates) || is_string($updates));
    $sql = 'UPDATE ' . static::$name . ' SET ';
    if (is_hasharray($updates)) {
      $keys = array_keys($updates);
      for ($i = 0; $i < count($keys); $i++) {
        $k = $keys[$i];
        assert(is_word($k));
        if ($i) {
          $sql .= ', ';
        }
        $sql .= '`' . $k . '` = ' . self::escape($updates[$k]);
      }
    } elseif (is_string($updates)) {
      $sql .= $updates;
    }
    $sql .= ' WHERE ' . self::parseWhere($where);
    $con = MySQLConnector::get();
    return mysql_query($sql, $con);
  }

  ////
  // private

  private static function parseWhere($where) {
    if (is_plainarray($where)) {
      $query = array_shift($where);
      $params = $where;
      $question_cnt = preg_match('/\?/', $query);
      assert(count($params) === $question_cnt);
      foreach ($params as $p) {
        $query = preg_replace('/\?/', self::escape($p), $query, 1);
      }
      return $query;
    }

    if (is_array($where)) {
      $query = '';
      $isFirst = true;
      foreach ($where as $k => $v) {
        if (! $isFirst) {
          $query .= ' AND ';
        }
        $query .= $k . ' = ' . self::escape($v);
        $isFirst = false;
      }
      return $query;
    }

    assert(is_string($where));
    return $where;
  }

  private static function escape($value) {
    assert(is_scalar($value));
    return "'" . mysql_real_escape_string($value, MySQLConnector::get()) . "'";
  }
}