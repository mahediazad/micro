<?php

/**
 * This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license MIT License
 */

namespace Propel\Tests\Runtime\Util;

use Propel\Tests\Helpers\Bookstore\BookstoreTestBase;
use Propel\Tests\Bookstore\BookQuery;
use Propel\Tests\Bookstore\Bookstore;
use Propel\Tests\Bookstore\BookstoreQuery;
use Propel\Tests\Bookstore\Map\AuthorTableMap;
use Propel\Tests\Bookstore\Map\BookTableMap;
use Propel\Tests\Bookstore\Map\BookstoreTableMap;
use Propel\Runtime\Propel;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\Exception\PropelException;
use Propel\Runtime\Map\TableMap;

/**
 * Tests the TableMap classes.
 *
 * @see BookstoreDataPopulator
 * @author Hans Lellelid <hans@xmpl.org>
 */
class TableMapTest extends BookstoreTestBase
{

    /**
     * @link http://propel.phpdb.org/trac/ticket/425
     */
    public function testMultipleFunctionInCriteria()
    {
        $db = Propel::getServiceContainer()->getAdapter(BookTableMap::DATABASE_NAME);
        try {
            $c = new Criteria();
            $c->setDistinct();
            if ($db instanceof PgsqlAdapter) {
                $c->addSelectColumn("substring(".BookTableMap::TITLE." from position('Potter' in ".BookTableMap::TITLE.")) AS col");
            } else {
                $this->markTestSkipped('Configured database vendor is not PostgreSQL');
            }
            $obj = BookQuery::create(null, $c)->find();
        } catch (PropelException $x) {
            $this->fail("Paring of nested functions failed: " . $x->getMessage());
        }
    }

    public function testNeedsSelectAliases()
    {
        $c = new Criteria();
        $this->assertFalse($c->needsSelectAliases(), 'Empty Criterias don\'t need aliases');

        $c = new Criteria();
        $c->addSelectColumn(BookTableMap::ID);
        $c->addSelectColumn(BookTableMap::TITLE);
        $this->assertFalse($c->needsSelectAliases(), 'Criterias with distinct column names don\'t need aliases');

        $c = new Criteria();
        BookTableMap::addSelectColumns($c);
        $this->assertFalse($c->needsSelectAliases(), 'Criterias with only the columns of a model don\'t need aliases');

        $c = new Criteria();
        $c->addSelectColumn(BookTableMap::ID);
        $c->addSelectColumn(AuthorTableMap::ID);
        $this->assertTrue($c->needsSelectAliases(), 'Criterias with common column names do need aliases');
    }

    public function testDoCountDuplicateColumnName()
    {
        $con = Propel::getServiceContainer()->getReadConnection(BookTableMap::DATABASE_NAME);
        $c = new Criteria();
        $c->addSelectColumn(BookTableMap::ID);
        $c->addJoin(BookTableMap::AUTHOR_ID, AuthorTableMap::ID);
        $c->addSelectColumn(AuthorTableMap::ID);
        $c->setLimit(3);
        try {
            $count = $c->doCount($con);
        } catch (Exception $e) {
            $this->fail('doCount() cannot deal with a criteria selecting duplicate column names ');
        }
    }

    public function testBigIntIgnoreCaseOrderBy()
    {
        BookstoreTableMap::doDeleteAll();

        // Some sample data
        $b = new Bookstore();
        $b->setStoreName("SortTest1")->setPopulationServed(2000)->save();

        $b = new Bookstore();
        $b->setStoreName("SortTest2")->setPopulationServed(201)->save();

        $b = new Bookstore();
        $b->setStoreName("SortTest3")->setPopulationServed(302)->save();

        $b = new Bookstore();
        $b->setStoreName("SortTest4")->setPopulationServed(10000000)->save();

        $c = new Criteria();
        $c->setIgnoreCase(true);
        $c->add(BookstoreTableMap::STORE_NAME, 'SortTest%', Criteria::LIKE);
        $c->addAscendingOrderByColumn(BookstoreTableMap::POPULATION_SERVED);

        $rows = BookstoreQuery::create(null, $c)->find();
        $this->assertEquals('SortTest2', $rows[0]->getStoreName());
        $this->assertEquals('SortTest3', $rows[1]->getStoreName());
        $this->assertEquals('SortTest1', $rows[2]->getStoreName());
        $this->assertEquals('SortTest4', $rows[3]->getStoreName());
    }

    /**
     *
     */
    public function testMixedJoinOrder()
    {
        $this->markTestSkipped('Famous cross join problem, to be solved one day');
        $c = new Criteria(BookTableMap::DATABASE_NAME);
        $c->addSelectColumn(BookTableMap::ID);
        $c->addSelectColumn(BookTableMap::TITLE);

        $c->addJoin(BookTableMap::PUBLISHER_ID, PublisherTableMap::ID, Criteria::LEFT_JOIN);
        $c->addJoin(BookTableMap::AUTHOR_ID, AuthorTableMap::ID);

        $params = array();
        $sql = $c->createSelectSql($params);

        $expectedSql = "SELECT book.ID, book.TITLE FROM book LEFT JOIN publisher ON (book.PUBLISHER_ID=publisher.ID), author WHERE book.AUTHOR_ID=author.ID";
        $this->assertEquals($expectedSql, $sql);
    }

    public function testMssqlApplyLimitNoOffset()
    {
        $db = Propel::getServiceContainer()->getAdapter(BookTableMap::DATABASE_NAME);
        if (! ($db instanceof MssqlAdapter)) {
            $this->markTestSkipped('Configured database vendor is not MsSQL');
        }

        $c = new Criteria(BookTableMap::DATABASE_NAME);
        $c->addSelectColumn(BookTableMap::ID);
        $c->addSelectColumn(BookTableMap::TITLE);
        $c->addSelectColumn(PublisherTableMap::NAME);
        $c->addAsColumn('PublisherName','(SELECT MAX(publisher.NAME) FROM publisher WHERE publisher.ID = book.PUBLISHER_ID)');

        $c->addJoin(BookTableMap::PUBLISHER_ID, PublisherTableMap::ID, Criteria::LEFT_JOIN);

        $c->setOffset(0);
        $c->setLimit(20);

        $params = array();
        $sql = $c->createSelectSql($params);

        $expectedSql = "SELECT TOP 20 book.ID, book.TITLE, publisher.NAME, (SELECT MAX(publisher.NAME) FROM publisher WHERE publisher.ID = book.PUBLISHER_ID) AS PublisherName FROM book LEFT JOIN publisher ON (book.PUBLISHER_ID=publisher.ID)";
        $this->assertEquals($expectedSql, $sql);
    }

    public function testMssqlApplyLimitWithOffset()
    {
        $db = Propel::getServiceContainer()->getAdapter(BookTableMap::DATABASE_NAME);
        if (! ($db instanceof MssqlAdapter)) {
            $this->markTestSkipped('Configured database vendor is not MsSQL');
        }

        $c = new Criteria(BookTableMap::DATABASE_NAME);
        $c->addSelectColumn(BookTableMap::ID);
        $c->addSelectColumn(BookTableMap::TITLE);
        $c->addSelectColumn(PublisherTableMap::NAME);
        $c->addAsColumn('PublisherName','(SELECT MAX(publisher.NAME) FROM publisher WHERE publisher.ID = book.PUBLISHER_ID)');
        $c->addJoin(BookTableMap::PUBLISHER_ID, PublisherTableMap::ID, Criteria::LEFT_JOIN);
        $c->setOffset(20);
        $c->setLimit(20);

        $params = array();

        $expectedSql = "SELECT [book.ID], [book.TITLE], [publisher.NAME], [PublisherName] FROM (SELECT ROW_NUMBER() OVER(ORDER BY book.ID) AS [RowNumber], book.ID AS [book.ID], book.TITLE AS [book.TITLE], publisher.NAME AS [publisher.NAME], (SELECT MAX(publisher.NAME) FROM publisher WHERE publisher.ID = book.PUBLISHER_ID) AS [PublisherName] FROM book LEFT JOIN publisher ON (book.PUBLISHER_ID=publisher.ID)) AS derivedb WHERE RowNumber BETWEEN 21 AND 40";
        $sql = $c->createSelectSql($params);
        $this->assertEquals($expectedSql, $sql);
    }

    public function testMssqlApplyLimitWithOffsetOrderByAggregate()
    {
        $db = Propel::getServiceContainer()->getAdapter(BookTableMap::DATABASE_NAME);
        if (! ($db instanceof MssqlAdapter)) {
            $this->markTestSkipped('Configured database vendor is not MsSQL');
        }

        $c = new Criteria(BookTableMap::DATABASE_NAME);
        $c->addSelectColumn(BookTableMap::ID);
        $c->addSelectColumn(BookTableMap::TITLE);
        $c->addSelectColumn(PublisherTableMap::NAME);
        $c->addAsColumn('PublisherName','(SELECT MAX(publisher.NAME) FROM publisher WHERE publisher.ID = book.PUBLISHER_ID)');
        $c->addJoin(BookTableMap::PUBLISHER_ID, PublisherTableMap::ID, Criteria::LEFT_JOIN);
        $c->addDescendingOrderByColumn('PublisherName');
        $c->setOffset(20);
        $c->setLimit(20);

        $params = array();

        $expectedSql = "SELECT [book.ID], [book.TITLE], [publisher.NAME], [PublisherName] FROM (SELECT ROW_NUMBER() OVER(ORDER BY (SELECT MAX(publisher.NAME) FROM publisher WHERE publisher.ID = book.PUBLISHER_ID) DESC) AS [RowNumber], book.ID AS [book.ID], book.TITLE AS [book.TITLE], publisher.NAME AS [publisher.NAME], (SELECT MAX(publisher.NAME) FROM publisher WHERE publisher.ID = book.PUBLISHER_ID) AS [PublisherName] FROM book LEFT JOIN publisher ON (book.PUBLISHER_ID=publisher.ID)) AS derivedb WHERE RowNumber BETWEEN 21 AND 40";
        $sql = $c->createSelectSql($params);
        $this->assertEquals($expectedSql, $sql);
    }

    public function testMssqlApplyLimitWithOffsetMultipleOrderBy()
    {
        $db = Propel::getServiceContainer()->getAdapter(BookTableMap::DATABASE_NAME);
        if (! ($db instanceof MssqlAdapter)) {
            $this->markTestSkipped('Configured database vendor is not MsSQL');
        }

        $c = new Criteria(BookTableMap::DATABASE_NAME);
        $c->addSelectColumn(BookTableMap::ID);
        $c->addSelectColumn(BookTableMap::TITLE);
        $c->addSelectColumn(PublisherTableMap::NAME);
        $c->addAsColumn('PublisherName','(SELECT MAX(publisher.NAME) FROM publisher WHERE publisher.ID = book.PUBLISHER_ID)');
        $c->addJoin(BookTableMap::PUBLISHER_ID, PublisherTableMap::ID, Criteria::LEFT_JOIN);
        $c->addDescendingOrderByColumn('PublisherName');
        $c->addAscendingOrderByColumn(BookTableMap::TITLE);
        $c->setOffset(20);
        $c->setLimit(20);

        $params = array();

        $expectedSql = "SELECT [book.ID], [book.TITLE], [publisher.NAME], [PublisherName] FROM (SELECT ROW_NUMBER() OVER(ORDER BY (SELECT MAX(publisher.NAME) FROM publisher WHERE publisher.ID = book.PUBLISHER_ID) DESC, book.TITLE ASC) AS [RowNumber], book.ID AS [book.ID], book.TITLE AS [book.TITLE], publisher.NAME AS [publisher.NAME], (SELECT MAX(publisher.NAME) FROM publisher WHERE publisher.ID = book.PUBLISHER_ID) AS [PublisherName] FROM book LEFT JOIN publisher ON (book.PUBLISHER_ID=publisher.ID)) AS derivedb WHERE RowNumber BETWEEN 21 AND 40";
        $sql = $c->createSelectSql($params);
        $this->assertEquals($expectedSql, $sql);
    }

    /**
     * @expectedException \Propel\Runtime\Exception\PropelException
     */
    public function testDoDeleteNoCondition()
    {
        $con = Propel::getServiceContainer()->getWriteConnection(BookTableMap::DATABASE_NAME);
        $c = new Criteria(BookTableMap::DATABASE_NAME);
        $c->doDelete($con);
    }

    /**
     * @expectedException \Propel\Runtime\Exception\PropelException
     */
    public function testDoDeleteJoin()
    {
        $con = Propel::getServiceContainer()->getWriteConnection(BookTableMap::DATABASE_NAME);
        $c = new Criteria(BookTableMap::DATABASE_NAME);
        $c->add(BookTableMap::TITLE, 'War And Peace');
        $c->addJoin(BookTableMap::AUTHOR_ID, AuthorTableMap::ID);
        $c->doDelete($con);
    }

    public function testDoDeleteSimpleCondition()
    {
        $con = Propel::getServiceContainer()->getWriteConnection(BookTableMap::DATABASE_NAME);
        $c = new Criteria(BookTableMap::DATABASE_NAME);
        $c->add(BookTableMap::TITLE, 'War And Peace');
        $c->doDelete($con);
        $expectedSQL = $this->getSql("DELETE FROM `book` WHERE book.TITLE='War And Peace'");
        $this->assertEquals($expectedSQL, $con->getLastExecutedQuery(), 'doDelete() translates a condition into a WHERE');
    }

    public function testDoDeleteSeveralConditions()
    {
        $con = Propel::getServiceContainer()->getWriteConnection(BookTableMap::DATABASE_NAME);
        $c = new Criteria(BookTableMap::DATABASE_NAME);
        $c->add(BookTableMap::TITLE, 'War And Peace');
        $c->add(BookTableMap::ID, 12);
        $c->doDelete($con);
        $expectedSQL = $this->getSql("DELETE FROM `book` WHERE book.TITLE='War And Peace' AND book.ID=12");
        $this->assertEquals($expectedSQL, $con->getLastExecutedQuery(), 'doDelete() combines conditions in WHERE with an AND');
    }

    public function testDoDeleteTableAlias()
    {
        if ($this->runningOnSQLite()) {
            $this->markTestSkipped('SQLite does not support Alias in Deletes');
        }
        $con = Propel::getServiceContainer()->getWriteConnection(BookTableMap::DATABASE_NAME);
        $c = new Criteria(BookTableMap::DATABASE_NAME);
        $c->addAlias('b', BookTableMap::TABLE_NAME);
        $c->add('b.TITLE', 'War And Peace');
        $c->doDelete($con);

        if ($this->isDb('pgsql')) {
            $expectedSQL = $this->getSql("DELETE FROM `book` AS b WHERE b.TITLE='War And Peace'");
        } else {
            $expectedSQL = $this->getSql("DELETE b FROM `book` AS b WHERE b.TITLE='War And Peace'");
        }

        $this->assertEquals($expectedSQL, $con->getLastExecutedQuery(), 'doDelete() accepts a Criteria with a table alias');
    }

    /**
     * Not documented anywhere, and probably wrong
     * @see http://www.propelorm.org/ticket/952
     */
    public function testDoDeleteSeveralTables()
    {
        $con = Propel::getServiceContainer()->getWriteConnection(BookTableMap::DATABASE_NAME);
        $count = $con->getQueryCount();
        $c = new Criteria(BookTableMap::DATABASE_NAME);
        $c->add(BookTableMap::TITLE, 'War And Peace');
        $c->add(AuthorTableMap::FIRST_NAME, 'Leo');
        $c->doDelete($con);
        $expectedSQL = $this->getSql("DELETE FROM `author` WHERE author.FIRST_NAME='Leo'");
        $this->assertEquals($expectedSQL, $con->getLastExecutedQuery(), 'doDelete() issues two DELETE queries when passed conditions on two tables');
        $this->assertEquals($count + 2, $con->getQueryCount(), 'doDelete() issues two DELETE queries when passed conditions on two tables');

        $c = new Criteria(BookTableMap::DATABASE_NAME);
        $c->add(AuthorTableMap::FIRST_NAME, 'Leo');
        $c->add(BookTableMap::TITLE, 'War And Peace');
        $c->doDelete($con);
        $expectedSQL = $this->getSql("DELETE FROM `book` WHERE book.TITLE='War And Peace'");
        $this->assertEquals($expectedSQL, $con->getLastExecutedQuery(), 'doDelete() issues two DELETE queries when passed conditions on two tables');
        $this->assertEquals($count + 4, $con->getQueryCount(), 'doDelete() issues two DELETE queries when passed conditions on two tables');
    }

    public function testCommentDoSelect()
    {
        $c = new Criteria();
        $c->setComment('Foo');
        $c->addSelectColumn(BookTableMap::ID);
        $expected = $this->getSql('SELECT /* Foo */ book.ID FROM `book`');
        $params = array();
        $this->assertEquals($expected, $c->createSelectSQL($params), 'Criteria::setComment() adds a comment to select queries');
    }

    public function testCommentDoUpdate()
    {
        $c1 = new Criteria();
        $c1->setPrimaryTableName(BookTableMap::TABLE_NAME);
        $c1->setComment('Foo');
        $c2 = new Criteria();
        $c2->add(BookTableMap::TITLE, 'Updated Title');
        $con = Propel::getServiceContainer()->getConnection(BookTableMap::DATABASE_NAME);
        $c1->doUpdate($c2, $con);
        $expected = $this->getSql('UPDATE /* Foo */ `book` SET `TITLE`=\'Updated Title\'');
        $this->assertEquals($expected, $con->getLastExecutedQuery(), 'Criteria::setComment() adds a comment to update queries');
    }

    public function testCommentDoDelete()
    {
        $c = new Criteria();
        $c->setComment('Foo');
        $c->add(BookTableMap::TITLE, 'War And Peace');
        $con = Propel::getServiceContainer()->getConnection(BookTableMap::DATABASE_NAME);
        $c->doDelete($con);
        $expected = $this->getSql('DELETE /* Foo */ FROM `book` WHERE book.TITLE=\'War And Peace\'');
        $this->assertEquals($expected, $con->getLastExecutedQuery(), 'Criteria::setComment() adds a comment to delete queries');
    }

    public function testIneffectualUpdateUsingBookObject()
    {
        $con = Propel::getConnection(BookTableMap::DATABASE_NAME);
        $book = BookQuery::create()->findOne($con);
        $count = $con->getQueryCount();
        $book->setTitle($book->getTitle());
        $book->setISBN($book->getISBN());

        try {
            $rowCount = $book->save($con);
            $this->assertEquals(0, $rowCount, 'save() should indicate zero rows updated');
        } catch (Exception $ex) {
            $this->fail('save() threw an exception');
        }

        $this->assertEquals($count, $con->getQueryCount(), 'save() does not execute any queries when there are no changes');
    }
}
