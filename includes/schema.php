<?php
/**
 * EcoTrack — Reading database/ecotrack.sql.
 *
 * The schema file is the single source of truth. The migration script and
 * the test suite both build tables from it rather than keeping their own
 * copies of the CREATE TABLE statements.
 */

const SCHEMA_FILE = __DIR__ . '/../database/ecotrack.sql';

/**
 * Split SQL into statements, ignoring semicolons inside quotes and dropping
 * "--" comments.
 *
 * @return string[]
 */
function splitSqlStatements(string $sql): array
{
    $statements = [];
    $current = '';
    $quote = null;
    $length = strlen($sql);

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];

        if ($quote === null && $char === '-' && ($sql[$i + 1] ?? '') === '-') {
            $end = strpos($sql, "\n", $i);
            $i = $end === false ? $length : $end;
            $current .= "\n";
            continue;
        }

        if ($quote !== null) {
            $current .= $char;
            if ($char === '\\') {
                $current .= $sql[++$i] ?? '';
            } elseif ($char === $quote) {
                $quote = null;
            }
            continue;
        }

        if ($char === "'" || $char === '"' || $char === '`') {
            $quote = $char;
        }

        if ($char === ';') {
            if (trim($current) !== '') {
                $statements[] = trim($current);
            }
            $current = '';
            continue;
        }

        $current .= $char;
    }

    if (trim($current) !== '') {
        $statements[] = trim($current);
    }

    return $statements;
}

/**
 * The schema file broken into its parts.
 *
 * @return array{tables: array<string, string>, seeds: string[]}
 *         tables: table name => CREATE TABLE statement, in dependency order
 *         seeds:  the INSERT statements for reference and demo data
 */
function schemaDefinition(): array
{
    $tables = [];
    $seeds = [];

    foreach (splitSqlStatements((string)file_get_contents(SCHEMA_FILE)) as $statement) {
        if (preg_match('/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?/i', $statement, $m)) {
            $tables[$m[1]] = $statement;
        } elseif (preg_match('/^INSERT\b/i', $statement)) {
            $seeds[] = $statement;
        }
    }

    return ['tables' => $tables, 'seeds' => $seeds];
}
