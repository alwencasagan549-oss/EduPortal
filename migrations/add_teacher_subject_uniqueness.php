<?php

require_once __DIR__ . '/../config/database.php';

$conn = getDBConnection();
$pdo = $conn->getPDO();
$driver = strtolower($conn->getDriverName());

try {
    if ($driver === 'mysql') {
        $schema = $pdo->query('SELECT DATABASE()')->fetchColumn();
        $indexRows = $pdo->prepare(
            "SELECT index_name, non_unique, GROUP_CONCAT(column_name ORDER BY seq_in_index) AS columns
             FROM information_schema.statistics
             WHERE table_schema = ? AND table_name = 'teachers'
             GROUP BY index_name, non_unique"
        );
        $indexRows->execute([$schema]);
        $emailIndexes = [];
        $subjectIndexExists = false;
        foreach ($indexRows->fetchAll() as $index) {
            $columns = strtolower((string) $index['columns']);
            if ((int) $index['non_unique'] === 0 && strpos($columns, 'email') !== false) {
                $emailIndexes[] = $index['index_name'];
            }
            if ((int) $index['non_unique'] === 0 && trim($columns) === 'subject') {
                $subjectIndexExists = true;
            }
        }

        $pdo->exec("UPDATE teachers SET subject = 'Programming' WHERE LOWER(TRIM(subject)) = 'programming'");
        foreach ($emailIndexes as $indexName) {
            if (preg_match('/^[A-Za-z0-9_]+$/', $indexName) !== 1) {
                throw new RuntimeException('Unexpected email index name.');
            }
            $pdo->exec('ALTER TABLE teachers DROP INDEX `' . $indexName . '`');
        }

        $duplicates = $pdo->query(
            'SELECT subject, COUNT(*) AS total FROM teachers GROUP BY subject HAVING COUNT(*) > 1'
        )->fetchAll();
        if ($duplicates !== []) {
            throw new RuntimeException('Duplicate teacher subjects must be resolved before adding the unique subject index.');
        }

        if (!$subjectIndexExists) {
            $pdo->exec('ALTER TABLE teachers ADD UNIQUE KEY teachers_subject_unique (subject)');
        }
    } else {
        $constraints = $pdo->query(
            "SELECT c.conname
             FROM pg_constraint c
             JOIN pg_attribute a
               ON a.attrelid = c.conrelid AND a.attnum = ANY(c.conkey)
             WHERE c.conrelid = 'teachers'::regclass
               AND c.contype = 'u'
               AND a.attname = 'email'"
        )->fetchAll();
        $pdo->exec("UPDATE teachers SET subject = 'Programming' WHERE LOWER(TRIM(subject)) = 'programming'");
        foreach ($constraints as $constraint) {
            $constraintName = (string) $constraint['conname'];
            if (preg_match('/^[A-Za-z0-9_]+$/', $constraintName) !== 1) {
                throw new RuntimeException('Unexpected email constraint name.');
            }
            $pdo->exec('ALTER TABLE teachers DROP CONSTRAINT "' . $constraintName . '"');
        }

        $duplicates = $pdo->query(
            'SELECT subject, COUNT(*) AS total FROM teachers GROUP BY subject HAVING COUNT(*) > 1'
        )->fetchAll();
        if ($duplicates !== []) {
            throw new RuntimeException('Duplicate teacher subjects must be resolved before adding the unique subject constraint.');
        }

        $subjectConstraint = $pdo->prepare(
            "SELECT 1 FROM pg_constraint
             WHERE conrelid = 'teachers'::regclass
               AND contype = 'u'
               AND conname = 'teachers_subject_unique'"
        );
        $subjectConstraint->execute();
        if ($subjectConstraint->fetchColumn() === false) {
            $pdo->exec('ALTER TABLE teachers ADD CONSTRAINT teachers_subject_unique UNIQUE (subject)');
        }
    }

    echo "Teacher subject uniqueness migration is ready.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
