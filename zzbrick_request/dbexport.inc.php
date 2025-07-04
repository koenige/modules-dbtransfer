<?php

/**
 * dbtransfer module
 * export linked database records for a given record
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/dbtransfer
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2025 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


/**
 * export linked database records for a given record
 *
 * @return array
 */
function mod_dbtransfer_dbexport() {
	ini_set('max_execution_time', 0);
	$data = $_GET;
	if (!empty($data['table']) AND !empty($data['field']) AND !empty($data['record_id'])) {
		$data = mod_dbtransfer_dbexport_read($data);
		$data['export_successful'] = true;
		$data['export_translations_successful'] = mod_dbtransfer_dbexport_translate();
	}
	
	$page['query_strings'][] = 'table';
	$page['query_strings'][] = 'field';
	$page['query_strings'][] = 'record_id';
	$page['text'] = wrap_template('dbexport', $data);
	return $page;
}

/**
 * read data for a given ID in a record
 *
 * @param array $data
 * @return array
 */
function mod_dbtransfer_dbexport_read($data) {
	$sql = 'SELECT * FROM `%s` WHERE `%s` = %d';
	$sql = sprintf($sql
		, wrap_db_escape($data['table'])
		, wrap_db_escape($data['field'])
		, wrap_db_escape($data['record_id'])
	);
	// @todo check table and field from structure
	$record = wrap_db_fetch($sql);
	if (!$record) {
		$data['record_not_found'] = true;
		return $data;
	}
	
	$conditions[] = [$data['field'], $data['record_id']];
	$data['saved'] = [];
	$data['conditions'] = [];
	$data += mod_dbtransfer_dbexport_record($data['table'], $data['field'], $conditions, $data);
	return $data;
}

/**
 * read a record and get related records
 *
 * @param string $table
 * @param string $id_field
 * @param array $conditions
 * @param array $data
 * @param array $extra_condition
 */
function mod_dbtransfer_dbexport_record($table, $id_field, $conditions, $data, $extra_condition = '') {
	$sql = mf_dbtransfer_record_sql($table, $conditions, $extra_condition);
	$records = mod_dbtransfer_dbexport_records($sql, $table, $id_field, $conditions);

	$relations = mf_dbtransfer_relations($table);
	$table_rel = [];
	// check all relations
	foreach ($records as $record_id => $record) {
		if (!empty($data['saved'][$table][$record_id])) continue; // only once!
		if (empty($record['__from_log']))
			wrap_file_log('dbtransfer/dbexport', 'write', [time(), $table, $record_id, json_encode($record)]);
		else unset($record['__from_log']);
		$data['saved'][$table][$record_id] = $record;

		// get detail record relations
		foreach ($relations['details'] as $rel_id => $relation) {
			if (!$record[$relation['detail_field']]) continue;
			$key = sprintf('%s', $record[$relation['detail_field']]);
			$rel_key = sprintf('%s=>%s', $relation['master_table'], $table);
			if (in_array($rel_key, wrap_setting('dbtransfer_export_no_details'))) continue;
			foreach ($record as $field_key => $field_value) {
				$rel_id_key = sprintf(
					'%s=>%s.%s=%s', $relation['master_table'], $table 
					, $field_key, $field_value
				);
				if (in_array($rel_id_key, wrap_setting('dbtransfer_export_no_details_id'))) continue 2;
			}
			$table_rel[$relation['master_table']][$key] = [
				'id_field' => $relation['master_field'],
				'id' => $record[$relation['detail_field']]
			];
		}

		// get master record relations
		if (in_array($table, wrap_setting('dbtransfer_export_no_masters'))) continue;
		foreach ($relations['masters'] as $rel_id => $relation) {
			$key = sprintf('%s-%s', $relation['detail_field'], $record_id);
			$rel_key = sprintf('%s.%s=>%s', $relation['detail_table'], $relation['detail_field'], $table);
			if (in_array($rel_key, wrap_setting('dbtransfer_export_no_masters'))) continue;
			$table_rel[$relation['detail_table']][$key] = [
				'foreign_key_field' => $relation['detail_field'],
				'id_field' => $relation['detail_id_field'],
				'id_foreign' => $record_id
			];
			$rel_key_2 = sprintf('%s=>%s', $table, $relation['detail_table']);
			foreach (wrap_setting('dbtransfer_export_conditions') as $cond) {
				if (!str_starts_with($cond, $rel_key_2.':')) continue;
				$table_rel[$relation['detail_table']]['extra_condition'] = substr($cond, strlen($rel_key_2) + 1);
			}
		}
	}
	
	// create WHERE conditions
	foreach ($table_rel as $table_name => $lines) {
		$conditions = [];
		$extra_condition = $lines['extra_condition'] ?? '';
		if ($extra_condition) unset($lines['extra_condition']);
		foreach ($lines as $line) {
			if (array_key_exists('id', $line)) {
				foreach (wrap_setting('dbtransfer_export_debug_ids') as $debug) {
					$debug = explode('=', $debug);
					if ($table_name === $debug[0] AND $line['id'].'' === $debug[1].'') {
						echo $sql;
						echo wrap_print(wrap_setting('dbtransfer_export_no_details_id'));
						echo wrap_print($table);
						echo wrap_print($id_field);
						echo wrap_print($table_rel);
						exit;
					}
				}
			}
			if (isset($line['foreign_key_field'])) {
				if (!empty($data['conditions'][$table_name][$line['foreign_key_field']][$line['id_foreign']]))
					continue;
				$conditions[] = [$line['foreign_key_field'], $line['id_foreign']];
				$data['conditions'][$table_name][$line['foreign_key_field']][$line['id_foreign']] = true;
			} else {
				if (!empty($data['conditions'][$table_name][$line['id_field']][$line['id']]))
					continue;
				$conditions[] = [$line['id_field'], $line['id']];
				$data['conditions'][$table_name][$line['id_field']][$line['id']] = true;
			}
		}
		if (!$conditions) continue;
		$table_id_field = reset($lines);
		$table_id_field = $line['id_field'];
		$data = mod_dbtransfer_dbexport_record($table_name, $table_id_field, $conditions, $data, $extra_condition);
	}
	return $data;
}

/**
 * @param string $sql
 * @param string $table
 * @param string $id_field
 * @param array $conditions
 * @return array
 */
function mod_dbtransfer_dbexport_records($sql, $table, $id_field, $conditions) {
	static $log = [];
	if (!$log) $log = wrap_file_log('dbtransfer/dbexport');

	$ids = [];
	$strings = [];
	foreach ($conditions as $condition) {
		$json = vsprintf('"%s":"%s"', $condition);
		$strings[$json] = $json;
	}

	// all conditions in log?
	// @todo this takes too long, faster to delete the file and start over again
	$logged = [];
	$last_index = 0;
	foreach ($log as $index => $line) {
		if ($line['table'] !== $table) continue;
		$found = false;
		foreach ($strings as $json) {
			if (!strstr($line['record'], $json)) continue;
			$found = true;
		}
		if (!$found) continue;
		$logged[$line['record_id']] = json_decode($line['record'], true);
		$logged[$line['record_id']]['__from_log'] = true;
		$last_index = $index;
	}

	// @todo this is not 100 % correct
	// will not find records missing for whatever reason in the middle
	if ($logged AND $last_index !== count($log) - 1) return $logged;
	
	// read records from database
	$records = wrap_db_fetch($sql, $id_field);
	if (count($records) == count($logged)) return $logged; // do not write twice to log
	return $records;
}

/**
 * get translations for records
 * @todo we assume, both database have identical entries in 
 * `default_translationfields` table
 *
 * @return bool
 */
function mod_dbtransfer_dbexport_translate() {
	$fields = mf_dbtransfer_translationfields();
	$tables = [];
	foreach ($fields as $field)
		$tables[$field['table_name']] = $field['table_name'];
	$tables = array_values($tables);

	$log = wrap_file_log('dbtransfer/dbexport');
	// get table names + IDs
	$ids = [];
	foreach ($log as $line) {
		if (!in_array($line['table'], $tables)) continue;
		$ids[$line['table']][$line['record_id']] = $line['record_id'];
	}
	
	$sql_template = 'SELECT *
		FROM %s
		WHERE translationfield_id = %d
		AND field_id IN (%s)';
	foreach ($fields as $field) {
		if (!array_key_exists($field['table_name'], $ids)) continue;
		$table = sprintf('_translations_%s', $field['field_type']);
		$sql = sprintf($sql_template
			, $table
			, $field['translationfield_id']
			, implode(',', $ids[$field['table_name']])
		);
		$records = wrap_db_fetch($sql, 'translation_id');
		foreach ($records as $record_id => $record)
			wrap_file_log('dbtransfer/dbexport', 'write', [time(), $table, $record_id, json_encode($record)]);
	}
	return true;
}
