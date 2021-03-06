<?php
/**
 * Copyright (C) 2013  Gerrit Addiks.
 * This package (including this file) was released under the terms of the GPL-3.0.
 * You should have received a copy of the GNU General Public License along with this program.
 * If not, see <http://www.gnu.org/licenses/> or send me a mail so i can send you a copy.
 * @license GPL-3.0
 * @author Gerrit Addiks <gerrit@addiks.de>
 * @package Addiks
 */

namespace Addiks\PHPSQL\Schema;

use ErrorException;
use InvalidArgumentException;
use Addiks\PHPSQL\Database\DatabaseSchema;
use Addiks\PHPSQL\Database\DatabaseSchemaInterface;
use Addiks\PHPSQL\Filesystem\FilesystemInterface;
use Addiks\PHPSQL\Value\Database\Dsn\InternalDsn;
use Addiks\PHPSQL\Table\TableSchema;
use Addiks\PHPSQL\Filesystem\FilePathes;

class SchemaManager
{

    const DATABASE_ID_DEFAULT = "default";
    const DATABASE_ID_META_MYSQL = "mysql";
    const DATABASE_ID_META_INFORMATION_SCHEMA = "information_schema";
    const DATABASE_ID_META_PERFORMANCE_SCHEMA = "performance_schema";
    const DATABASE_ID_META_INDICES = "indicies";

    public function __construct(FilesystemInterface $filesystem)
    {
        $this->filesystem = $filesystem;
    }

    protected $filesystem;

    public function getFilesystem()
    {
        return $this->filesystem;
    }

    private $currentDatabaseId = SchemaManager::DATABASE_ID_DEFAULT;

    public function getCurrentlyUsedDatabaseId()
    {
        return $this->currentDatabaseId;
    }

    public function setCurrentlyUsedDatabaseId($schemaId)
    {

        $pattern = InternalDsn::PATTERN;
        if (!preg_match("/{$pattern}/is", $schemaId)) {
            throw new InvalidArgumentException(
                "Invalid database-id '{$schemaId}' given! (Does not match pattern '{$pattern}')"
            );
        }

        if (!$this->schemaExists($schemaId)) {
            throw new InvalidArgumentException("Database '{$schemaId}' does not exist!");
        }

        $this->currentDatabaseId = $schemaId;

        return true;
    }

    protected $schemas = array();

    public function setSchema($schemaId, DatabaseSchemaInterface $schema)
    {
        $this->schemas[$schemaId] = $schema;
    }

    /**
     * Gets the schema for a database.
     * The schema contains information about existing tables/views/etc.
     *
     * @param string $schemaId
     * @throws ErrorException
     * @return DatabaseSchemaInterface
     */
    public function getSchema($schemaId = null)
    {

        if (is_null($schemaId)) {
            $schemaId = $this->getCurrentlyUsedDatabaseId();
        }

        if (!$this->schemaExists(self::DATABASE_ID_DEFAULT)) {
            $this->createSchema(self::DATABASE_ID_DEFAULT);
        }

        $pattern = InternalDsn::PATTERN;
        if (!preg_match("/{$pattern}/is", $schemaId)) {
            throw new ErrorException("Invalid database-id '{$schemaId}' given! (Does not match pattern '{$pattern}')");
        }

        if (!isset($this->schemas[$schemaId])) {
            $schemaFilePath = sprintf(FilePathes::FILEPATH_SCHEMA, $schemaId);
            $schemaFile = $this->filesystem->getFile($schemaFilePath);
            $this->schemas[$schemaId] = new DatabaseSchema($schemaFile);
        }

        return $this->schemas[$schemaId];
    }

    public function isMetaSchema($schemaId)
    {
        return in_array($schemaId, [
            self::DATABASE_ID_META_INDICES,
            self::DATABASE_ID_META_INFORMATION_SCHEMA,
            self::DATABASE_ID_META_MYSQL,
            self::DATABASE_ID_META_PERFORMANCE_SCHEMA,
        ]);
    }

    public function schemaExists($schemaId)
    {

        $pattern = InternalDsn::PATTERN;
        if (!preg_match("/{$pattern}/is", $schemaId)) {
            throw new ErrorException("Invalid database-id '{$schemaId}' given! (Does not match pattern '{$pattern}')");
        }

        if (isset($this->schemas[$schemaId])) {
            return true;
        }

        return $this->filesystem->fileExists(sprintf(FilePathes::FILEPATH_SCHEMA, $schemaId));
    }

    public function createSchema($schemaId)
    {

        $pattern = InternalDsn::PATTERN;
        if (!preg_match("/{$pattern}/is", $schemaId)) {
            throw new ErrorException("Invalid database-id '{$schemaId}' given! (Does not match pattern '{$pattern}')");
        }

        if ($this->schemaExists($schemaId)) {
            throw new ErrorException("Database '{$schemaId}' already exist!");
        }

        $schemaFilePath = sprintf(FilePathes::FILEPATH_SCHEMA, $schemaId);
        $schemaFile = $this->filesystem->getFile($schemaFilePath);

        /* @var $schema DatabaseSchema */
        $schema = new DatabaseSchema($schemaFile);
        $schema->setId($schemaId);

        return $schema;
    }

    public function removeSchema($schemaId)
    {

        $pattern = InternalDsn::PATTERN;
        if (!preg_match("/{$pattern}/is", $schemaId)) {
            throw new ErrorException("Invalid database-id '{$schemaId}' given! (Does not match pattern '{$pattern}')");
        }

        if ($this->isMetaSchema($schemaId)) {
            throw new ErrorException("Cannot remove or modify meta-database '{$schemaId}'!");
        }

        $schemaFilePath = sprintf(FilePathes::FILEPATH_SCHEMA, $schemaId);

        $this->filesystem->fileUnlink($schemaFilePath);
    }

    public function listSchemas()
    {

        if (!$this->schemaExists(self::DATABASE_ID_DEFAULT)) {
            $this->createSchema(self::DATABASE_ID_DEFAULT);
        }

        /* @var $filesystem FilesystemInterface */
        $filesystem = $this->filesystem;

        list($schemaPath, $suffix) = explode("%s", FilePathes::FILEPATH_SCHEMA);

        foreach ($filesystem->getDirectoryIterator($schemaPath) as $item) {
            /* @var $item DirectoryIterator */

            $filename = $item->getFilename();
            if (substr($filename, strlen($filename)-strlen($suffix)) === $suffix) {
                $result[] = substr($filename, 0, strlen($filename)-strlen($suffix));
            }
        }

    #   $result[] = self::DATABASE_ID_META_INDICES;
        $result[] = self::DATABASE_ID_META_INFORMATION_SCHEMA;
    #	$result[] = self::DATABASE_ID_META_PERFORMANCE_SCHEMA;
    #	$result[] = self::DATABASE_ID_META_MYSQL;

        $result = array_unique($result);

        return $result;
    }

    protected $tableSchemas = array();

    public function getTableSchema($tableName, $schemaId = null)
    {

        if (is_null($schemaId)) {
            $schemaId = $this->getCurrentlyUsedDatabaseId();
        }

        /* @var $databaseSchema DatabaseSchemaInterface */
        $databaseSchema = $this->getSchema($schemaId);

        if (is_numeric($tableName)) {
            $tableIndex = $tableName;
            $tableName = $databaseSchema->getTablePage($tableIndex)->getName();

        } else {
            $tableIndex = $databaseSchema->getTableIndex((string)$tableName);
        }

        if (is_null($tableIndex)) {
            return null;
        }

        $cacheKey = "{$schemaId}.{$tableIndex}";
        if (!isset($this->tableSchemas[$cacheKey])) {
            $tableSchemaFilepath = sprintf(FilePathes::FILEPATH_TABLE_SCHEMA, $schemaId, $tableIndex);
            $indexSchemaFilepath = sprintf(FilePathes::FILEPATH_TABLE_INDEX_SCHEMA, $schemaId, $tableIndex);

            $tableSchemaFile = $this->filesystem->getFile($tableSchemaFilepath);
            $indexSchemaFile = $this->filesystem->getFile($indexSchemaFilepath);

            $this->tableSchemas[$cacheKey] = $databaseSchema->createTableSchema(
                $tableSchemaFile,
                $indexSchemaFile,
                $tableName
            );
        }

        $this->tableSchemas[$cacheKey]->setDatabaseSchema($databaseSchema);

        return $this->tableSchemas[$cacheKey];
    }

    public function dropTable($tableName, $schemaId = null)
    {

        if (is_null($schemaId)) {
            $schemaId = $this->getCurrentlyUsedDatabaseId();
        }

        /* @var $schema DatabaseSchemaInterface */
        $schema = $this->getSchema($schemaId);

        if (!$schema->tableExists($tableName)) {
            throw new ErrorException("Table {$tableName} does not exist!");
        }

        $schema->unregisterTable($tableName);

    }

    ### VIEW

    public function getViewQuery($viewName, DatabaseSchemaInterface $schema = null)
    {

        if (is_null($schema)) {
            $schema = $this->getSchema();
        }

        $viewIndex = $schema->getViewIndex($viewName);

        if (is_null($viewIndex)) {
            return null;
        }

        $viewFilePath = sprintf(FilePathes::FILEPATH_VIEW_SQL, $schema->getId(), $viewIndex);
        return $this->filesystem->getFileContents($viewFilePath);
    }

    public function setViewQuery($query, $viewName, DatabaseSchemaInterface $schema = null)
    {

        if (is_null($schema)) {
            $schema = $this->getSchema();
        }

        $viewIndex = $schema->getViewIndex($viewName);

        if (is_null($viewIndex)) {
            $schema->registerView($viewName);
            $viewIndex = $schema->getViewIndex($viewName);
        }

        $viewFilePath = sprintf(FilePathes::FILEPATH_VIEW_SQL, $schema->getId(), $viewIndex);
        $this->filesystem->putFileContents($viewFilePath, $query);
    }
}
