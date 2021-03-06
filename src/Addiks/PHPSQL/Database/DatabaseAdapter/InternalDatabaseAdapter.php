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

namespace Addiks\PHPSQL\Database\DatabaseAdapter;

use ErrorException;
use InvalidArgumentException;
use Addiks\PHPSQL\Table\TableSchema;
use Addiks\PHPSQL\Table\TableManager;
use Addiks\PHPSQL\Table\TableFactory;
use Addiks\PHPSQL\Iterators\SQLTokenIterator;
use Addiks\PHPSQL\ValueResolver\ValueResolver;
use Addiks\PHPSQL\Result\TemporaryResult;
use Addiks\PHPSQL\Job\StatementJob;
use Addiks\PHPSQL\Value\Database\Dsn\Internal;
use Addiks\PHPSQL\Value\Text\Annotation;
use Addiks\PHPSQL\Value\Enum\Page\Schema\Engine;
use Addiks\PHPSQL\Database\AbstractDatabase;
use Addiks\PHPSQL\Database\InformationSchemaSchema;
use Addiks\PHPSQL\Schema\SchemaManager;
use Addiks\PHPSQL\Filesystem\RealFilesystem;
use Addiks\PHPSQL\SqlParser\SqlParser;
use Addiks\PHPSQL\SqlParser\Part\ParenthesisParser;
use Addiks\PHPSQL\SqlParser\SelectSqlParser;
use Addiks\PHPSQL\SqlParser\InsertSqlParser;
use Addiks\PHPSQL\SqlParser\UpdateSqlParser;
use Addiks\PHPSQL\SqlParser\DeleteSqlParser;
use Addiks\PHPSQL\SqlParser\ShowSqlParser;
use Addiks\PHPSQL\SqlParser\UseSqlParser;
use Addiks\PHPSQL\SqlParser\CreateSqlParser;
use Addiks\PHPSQL\SqlParser\AlterSqlParser;
use Addiks\PHPSQL\SqlParser\DropSqlParser;
use Addiks\PHPSQL\SqlParser\SetSqlParser;
use Addiks\PHPSQL\SqlParser\DescribeSqlParser;
use Addiks\PHPSQL\SqlParser\Part\Specifier\TableParser;
use Addiks\PHPSQL\SqlParser\Part\ConditionParser;
use Addiks\PHPSQL\SqlParser\Part\Specifier\ColumnParser;
use Addiks\PHPSQL\SqlParser\Part\ColumnDefinitionParser;
use Addiks\PHPSQL\StatementExecutor\StatementExecutorInterface;
use Addiks\PHPSQL\StatementExecutor\StatementExecutor;
use Addiks\PHPSQL\Column\ColumnDataFactory;
use Addiks\PHPSQL\Table\InformationSchemaTableFactory;

class InternalDatabaseAdapter implements DatabaseAdapterInterface
{

    /**
     * @var SqlParser
     */
    protected $sqlParser;

    public function getSqlParser()
    {
        if (is_null($this->sqlParser)) {
            $this->sqlParser = new SqlParser();
        }

        return $this->sqlParser;
    }

    /**
     * @var FilesystemInterface
     */
    protected $filesystem;

    public function getFilesystem()
    {
        if (is_null($this->filesystem)) {
            $this->filesystem = new RealFilesystem();
        }

        return $this->filesystem;
    }

    /**
     * @var SchemaManager
     */
    protected $schemaManager;

    public function getSchemaManager()
    {
        if (is_null($this->schemaManager)) {
            $this->schemaManager = new SchemaManager(
                $this->getFilesystem()
            );

            #$this->schemaManager->setSchema(
            #    SchemaManager::DATABASE_ID_META_INDICES,
            #    new Indicies($this->schemaManager)
            #);

            $this->schemaManager->setSchema(
                SchemaManager::DATABASE_ID_META_INFORMATION_SCHEMA,
                new InformationSchemaSchema($this->schemaManager)
            );
        }

        return $this->schemaManager;
    }

    /**
     * @var ValueResolver
     */
    protected $valueResolver;

    public function getValueResolver()
    {
        if (is_null($this->valueResolver)) {
            $this->valueResolver = new ValueResolver();
        }

        return $this->valueResolver;
    }

    protected $tableManager;

    public function getTableManager()
    {
        if (is_null($this->tableManager)) {
            $this->tableManager = new TableManager(
                $this->getFilesystem(),
                $this->getSchemaManager()
            );

            $columnDataFactory = new ColumnDataFactory($this->getFilesystem());

            $tableFactory = new TableFactory($this->getFilesystem(), $columnDataFactory);

            foreach ([
                Engine::MARIADB(),
                Engine::MYISAM(),
                Engine::INNODB()
            ] as $engine) {
                $this->tableManager->registerFactory(
                    $engine,
                    $tableFactory
                );
            }

            $this->tableManager->registerFactory(
                Engine::INFORMATION_SCHEMA(),
                new InformationSchemaTableFactory($this->schemaManager)
            );

            # TODO: add factories for all the other table-engines out there

        }

        return $this->tableManager;
    }

    protected $statementExecutor;

    public function getStatementExecutor()
    {
        if (is_null($this->statementExecutor)) {
            $this->statementExecutor = new StatementExecutor(
                $this->getSchemaManager(),
                $this->getTableManager(),
                $this->getValueResolver()
            );
        }

        return $this->statementExecutor;
    }

    /**
     * @var array
     */
    protected $executors = array();

    public function getTypeName()
    {
        return 'internal';
    }

    private $currentDatabaseId = SchemaManager::DATABASE_ID_DEFAULT;

    public function getCurrentlyUsedDatabaseId()
    {
        return $this->currentDatabaseId;
    }

    public function setCurrentlyUsedDatabaseId($schemaId)
    {

        $pattern = Internal::PATTERN;
        if (!preg_match("/{$pattern}/is", $schemaId)) {
            throw new InvalidArgument("Invalid database-id '{$schemaId}' given! (Does not match pattern '{$pattern}')");
        }

        if (!$this->schemaExists($schemaId)) {
            throw new InvalidArgumentException("Database '{$schemaId}' does not exist!");
        }

        $this->currentDatabaseId = $schemaId;

        return true;
    }

    public function query($statementString, array $parameters = array(), SQLTokenIterator $tokens = null)
    {

        $result = null;

        try {
            if (is_null($tokens)) {
                $tokens = new SQLTokenIterator($statementString);
            }

            $jobs = $this->getSqlParser()->convertSqlToJob($tokens);

            foreach ($jobs as $statement) {
                /* @var $statement StatementJob */

                $result = $this->queryStatement($statement, $parameters);
            }

        } catch (InvalidArgumentException $exception) {
            print($exception);

            throw $exception;

        } catch (MalformedSqlException $exception) {
            print($exception);

            throw $exception;
        }

        if (is_null($result)) {
            $result = new TemporaryResult();
        }

        return $result;
    }

    public function queryStatement(StatementJob $statement, array $parameters = array())
    {
        $result = $this->getStatementExecutor()->executeJob($statement, $parameters);

        return $result;
    }

    public function prepare($statementString, SQLTokenIterator $tokens = null)
    {
        if (is_null($tokens)) {
            $tokens = new SQLTokenIterator($statementString);
        }

        $jobs = $this->getSqlParser()->convertSqlToJob($tokens);

        return $jobs;
    }
}
