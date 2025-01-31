<?hh // strict

namespace Slack\SQLFake;

use namespace HH\Lib\{C, Dict, Keyset, Str, Vec};

/**
 * An executable Query plan
 *
 * Clause processors used by multiple query types are implemented here
 * Any clause used by only one query type is processed in that subclass
 */
abstract class Query {

	public ?Expression $whereClause = null;
	public ?order_by_clause $orderBy = null;
	public ?limit_clause $limitClause = null;

	/**
	 * The initial query that was executed, no longer needed after parsing but retained for
	 * debugging and logging
	 */
	public string $sql;
	public bool $ignoreDupes = false;

	protected function applyWhere(
		AsyncMysqlConnection $conn,
		dataset $data,
		index_refs $index_refs,
		keyset<arraykey> $dirty_pks,
		?dict<string, Column> $columns,
		?vec<Index> $indexes,
	): dataset {
		$where = $this->whereClause;
		if ($where === null) {
			// no where clause? cool! just return the given data
			return $data;
		}

		$all_matched = false;

		if ($columns is nonnull && $indexes) {
			$data = QueryPlanner::filterWithIndexes($data, $index_refs, $columns, $indexes, $where, inout $all_matched);
		}

		if (!$all_matched) {
			$data = Dict\filter($data, $row ==> (bool)$where->evaluate($row, $conn));
		}

		if (QueryContext::$useReplica && QueryContext::$inRequest && QueryContext::$preventReplicaReadsAfterWrites) {
			$intersection = Keyset\intersect(Keyset\keys($data), $dirty_pks);

			if ($intersection !== keyset[]) {
				throw new \Exception('Replica read after write: '.(QueryContext::$query ?? ''));
			}
		}

		return $data;
	}

	/**
	 * Apply the ORDER BY clause to sort the rows
	 */
	protected function applyOrderBy(AsyncMysqlConnection $_conn, dataset $data): dataset {
		$order_by = $this->orderBy;
		if ($order_by === null) {
			return $data;
		}

		// allow all column expressions to fall through to the full row
		foreach ($order_by as $rule) {
			$expr = $rule['expression'];
			if ($expr is ColumnExpression && $expr->tableName === null) {
				$expr->allowFallthrough();
			}
		}

		// sort function applies all ORDER BY criteria to compare two rows
		$sort_fun = (row $a, row $b): int ==> {
			foreach ($order_by as $rule) {
				// in applySelect, the order by expressions are pre-evaluated and saved on the row with their names as keys,
				// so we don't need to evaluate them again here
				$value_a = $a[$rule['expression']->name];
				$value_b = $b[$rule['expression']->name];

				if ($value_a != $value_b) {
					if ($value_a is num && $value_b is num) {
						return (
							((float)$value_a < (float)$value_b ? 1 : 0) ^
							(($rule['direction'] === SortDirection::DESC) ? 1 : 0)
						)
							? -1
							: 1;
					} else {
						return (
							// Use string comparison explicity to handle lexicographical ordering of things like '125' < '5'
							(((Str\compare((string)$value_a, (string)$value_b)) < 0) ? 1 : 0) ^
							(($rule['direction'] === SortDirection::DESC) ? 1 : 0)
						)
							? -1
							: 1;
					}

				}
			}
			return 0;
		};

		// Work around default sorting behavior to provide a usort that looks like MySQL, where equal values are ordered deterministically
		// record the keys in a dict for usort
		$data_temp = dict[];
		$offset = 0;
		foreach ($data as $i => $item) {
			$data_temp[$i] = tuple($i, $offset, $item);
			$offset++;
		}

		$data_temp = Dict\sort($data_temp, (
			(arraykey, int, dict<string, mixed>) $a,
			(arraykey, int, dict<string, mixed>) $b,
		): int ==> {
			$result = $sort_fun($a[2], $b[2]);

			if ($result !== 0) {
				return $result;
			}

			$a_index = $a[1];
			$b_index = $b[1];

			return $b_index > $a_index ? 1 : -1;
		});

		// re-key the input dataset
		$data_temp = vec($data_temp);
		// dicts maintain insert order. the keys will be inserted out of order but have to match the original
		// keys for updates/deletes to be able to delete the right rows
		$data = dict[];
		foreach ($data_temp as $item) {
			$data[$item[0]] = $item[2];
		}

		return $data;
	}

	protected function applyLimit(dataset $data): dataset {
		$limit = $this->limitClause;
		if ($limit === null) {
			return $data;
		}

		// keys in this dict are intentionally out of order if an ORDER BY clause occurred
		// so first we get the ordered keys, then slice that list by the limit clause, then select only those keys
		return Vec\keys($data)
			|> Vec\slice($$, $limit['offset'], $limit['rowcount'])
			|> Dict\select_keys($data, $$);
	}

	/**
	 * Parses a table name that may contain a . to reference another database
	 * Returns the fully qualified database name and table name as a tuple
	 * If there is no ".", the database name will be the connection's current database
	 */
	public static function parseTableName(AsyncMysqlConnection $conn, string $table): (string, string) {
		// referencing a table from another database on the same server?
		if (Str\contains($table, '.')) {
			$parts = Str\split($table, '.');
			if (C\count($parts) !== 2) {
				throw new SQLFakeRuntimeException("Table name $table has too many parts");
			}
			list($database, $table_name) = $parts;
			return tuple($database, $table_name);
		} else {
			// otherwise use connection context's database
			$database = $conn->getDatabase();
			return tuple($database, $table);
		}
	}

	/**
	 * Apply the "SET" clause of an UPDATE, or "ON DUPLICATE KEY UPDATE"
	 */
	protected function applySet(
		AsyncMysqlConnection $conn,
		string $database,
		string $table_name,
		dataset $filtered_rows,
		dataset $original_table,
		index_refs $index_refs,
		keyset<arraykey> $dirty_pks,
		vec<BinaryOperatorExpression> $set_clause,
		?TableSchema $table_schema,
		/* for dupe inserts only */
		?row $values = null,
	): (int, dataset, index_refs) {
		$valid_fields = null;
		if ($table_schema !== null) {
			$valid_fields = Keyset\map($table_schema->fields, $field ==> $field->name);
		}

		$columns = keyset[];
		$set_clauses = vec[];
		foreach ($set_clause as $expression) {
			// the parser already asserts this at parse time
			$left = $expression->left as ColumnExpression;
			$right = $expression->right as nonnull;
			$column = $left->name;
			$columns[] = $column;

			// If we know the valid fields for this table, only allow setting those
			if ($valid_fields !== null) {
				if (!C\contains($valid_fields, $column)) {
					throw new SQLFakeRuntimeException("Invalid update column {$column}");
				}
			}

			$set_clauses[] = shape('column' => $column, 'expression' => $right);
		}

		$primary_key_columns = $table_schema?->getPrimaryKeyColumns() ?? keyset[];
		$primary_key_changed = false;

		foreach ($set_clauses as $clause) {
			if (C\contains_key($primary_key_columns, $clause['column'])) {
				$primary_key_changed = true;
			}
		}

		$applicable_indexes = vec[];

		if ($table_schema is nonnull) {
			foreach ($table_schema->indexes as $index) {
				if ($primary_key_changed || Keyset\intersect($index->fields, $columns) !== keyset[]) {
					$applicable_indexes[] = $index;
				}
			}

			if ($table_schema->vitess_sharding) {
				$applicable_indexes[] = new Index(
					$table_schema->vitess_sharding->keyspace,
					'INDEX',
					keyset[$table_schema->vitess_sharding->sharding_key],
					true,
				);
			}
		}

		$update_count = 0;

		foreach ($filtered_rows as $row_id => $row) {
			$changes_found = false;

			// a copy of the $row to be updated
			$update_row = $row;
			if ($values is nonnull) {
				// this is a bit of a hack to make the VALUES() function work without changing the
				// interface of all ->evaluate() expressions to include the values list as well
				// we put the values on the row as though they were another table
				// we do this on a copy so that we don't accidentally save these to the table
				foreach ($values as $col => $val) {
					$update_row['sql_fake_values.'.$col] = $val;
				}
			}

			$index_ref_deletes = self::getIndexModificationsForRow($applicable_indexes, $row);

			foreach ($set_clauses as $clause) {
				$existing_value = $row[$clause['column']] ?? null;
				$expr = $clause['expression'];
				$new_value = $expr->evaluate($update_row, $conn);

				if ($new_value !== $existing_value) {
					$row[$clause['column']] = $new_value;
					$changes_found = true;
				}
			}

			$new_row_id = $row_id;
			$index_ref_additions = vec[];

			if ($changes_found) {
				if ($table_schema is nonnull) {
					// throw on invalid data types if strict mode
					$row = DataIntegrity::coerceToSchema($row, $table_schema);
				}

				foreach ($applicable_indexes as $index) {
					if ($index->type === 'PRIMARY' && C\count($index->fields) === 1) {
						$new_row_id = $row[C\firstx($index->fields)] as arraykey;
						break;
					}
				}

				$index_ref_additions = self::getIndexModificationsForRow($applicable_indexes, $row);
			}

			if ($changes_found) {
				if ($table_schema is nonnull) {
					$key_violation = false;

					if (C\contains_key($original_table, $new_row_id)) {
						$key_violation = true;
					} else {
						foreach ($index_ref_deletes as list($index_name, $index_keys, $store_as_unique)) {
							if ($store_as_unique) {
								$leaf = $index_refs[$index_name] ?? null;

								foreach ($index_keys as $index_key) {
									$leaf = $leaf[$index_key] ?? null;

									if ($leaf is null) {
										break;
									}

									if ($leaf is arraykey && $leaf !== $row_id) {
										$key_violation = true;
										break;
									}
								}
							}
						}
					}

					$result = null;
					if ($key_violation) {
						$result = DataIntegrity::checkUniqueConstraints($original_table, $row, $table_schema, $row_id);
					}

					if ($result is nonnull) {
						if ($this->ignoreDupes) {
							continue;
						}
						if (!QueryContext::$relaxUniqueConstraints) {
							throw new SQLFakeUniqueKeyViolation($result[0]);
						}
					}
				}

				foreach ($index_ref_deletes as list($index_name, $index_keys, $store_as_unique)) {
					$specific_index_refs = $index_refs[$index_name] ?? null;
					if ($specific_index_refs is nonnull) {
						self::removeFromIndexes(inout $specific_index_refs, $index_keys, $store_as_unique, $row_id);
						$index_refs[$index_name] = $specific_index_refs;
					}
				}

				foreach ($index_ref_additions as list($index_name, $index_keys, $store_as_unique)) {
					$specific_index_refs = $index_refs[$index_name] ?? dict[];
					self::addToIndexes(inout $specific_index_refs, $index_keys, $store_as_unique, $new_row_id);
					$index_refs[$index_name] = $specific_index_refs;
				}

				if (QueryContext::$inRequest) {
					$dirty_pks[] = $new_row_id;
				}

				if ($new_row_id !== $row_id) {
					// Remap keys to preserve insertion order when primary key has changed
					$original_table = Dict\pull_with_key(
						$original_table,
						($k, $v) ==> $k === $row_id ? $row : $v,
						($k, $_) ==> $k === $row_id ? $new_row_id : $k,
					);
				} else {
					$original_table[$row_id] = $row;
				}

				$update_count++;
			}
		}

		// write it back to the database
		$conn->getServer()->saveTable($database, $table_name, $original_table, $index_refs, $dirty_pks);
		return tuple($update_count, $original_table, $index_refs);
	}

	public static function getIndexModificationsForRow(
		vec<Index> $applicable_indexes,
		row $row,
	): vec<(string, vec<arraykey>, bool)> {
		$index_ref_deletes = vec[];

		foreach ($applicable_indexes as $index) {
			if ($index->type === 'PRIMARY' && C\count($index->fields) === 1) {
				continue;
			}

			$store_as_unique = $index->type === 'UNIQUE' || $index->type === 'PRIMARY';

			$index_field_count = C\count($index->fields);

			if ($index_field_count === 1) {
				$index_part = $row[C\firstx($index->fields)] as ?arraykey;

				if ($index_part is null) {
					$index_part = '__NULL__';
				}

				$index_key = vec[$index_part];
			} else {
				$index_key = vec[];

				$inc = 0;

				foreach ($index->fields as $field) {
					$index_part = $row[$field] as ?arraykey;

					if ($index_part is null) {
						$index_part = '__NULL__';

						// don't store unique indexes with null
						if ($index->type === 'UNIQUE' && $inc < $index_field_count - 1) {
							if ($inc > 0) {
								$store_as_unique = false;
							}

							break;
						}
					}

					$index_key[] = $index_part;

					$inc++;
				}

				// this happens if the first index column contains a null value — in which case
				// we don't store anything
				if ($index_key === vec[]) {
					continue;
				}
			}

			$index_ref_deletes[] = tuple($index->name, $index_key, $store_as_unique);
		}

		return $index_ref_deletes;
	}

	/**
	 * This is an ugly, ugly method — but I believe it's the only way to achieve this in Hack
	 */
	public static function removeFromIndexes(
		inout dict<arraykey, mixed> $index_refs,
		vec<arraykey> $index_keys,
		bool $store_as_unique,
		arraykey $row_id,
	): void {
		$key_length = C\count($index_keys);

		if ($key_length === 1) {
			if ($store_as_unique) {
				unset($index_refs[$index_keys[0]]);
			} else {
				/* HH_FIXME[4135] */
				unset(
					/* HH_FIXME[4063] */
					$index_refs[$index_keys[0]][$row_id]
				);
			}
		} else {
			$nested_indexes = $index_refs[$index_keys[0]] ?? null;

			if ($nested_indexes is dict<_, _>) {
				self::removeFromIndexes(inout $nested_indexes, Vec\drop($index_keys, 1), $store_as_unique, $row_id);

				if ($nested_indexes) {
					$index_refs[$index_keys[0]] = $nested_indexes;
				} else {
					unset($index_refs[$index_keys[0]]);
				}
			}

		}
	}

	/**
	 * This is an ugly, ugly method — but I believe it's the only way to achieve this in Hack
	 */
	public static function addToIndexes(
		inout dict<arraykey, mixed> $index_refs,
		vec<arraykey> $index_keys,
		bool $store_as_unique,
		arraykey $row_id,
	): void {
		$key_length = C\count($index_keys);

		if ($key_length === 1) {
			if ($store_as_unique) {
				$index_refs[$index_keys[0]] = $row_id;
			} else {
				$index_refs[$index_keys[0]] ??= keyset[];
				/* HH_FIXME[4006] */
				$index_refs[$index_keys[0]][] = $row_id;
			}
		} else if ($key_length > 1) {
			$nested_indexes = $index_refs[$index_keys[0]] ?? dict[];

			if ($nested_indexes is dict<_, _>) {
				self::addToIndexes(inout $nested_indexes, Vec\drop($index_keys, 1), $store_as_unique, $row_id);

				$index_refs[$index_keys[0]] = $nested_indexes;
			}
		}
	}
}
