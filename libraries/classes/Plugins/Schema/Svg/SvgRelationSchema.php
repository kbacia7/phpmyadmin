<?php
/**
 * Contains PhpMyAdmin\Plugins\Schema\Svg\RelationStatsSvg class
 *
 * @package PhpMyAdmin
 */
declare(strict_types=1);

namespace PhpMyAdmin\Plugins\Schema\Svg;

use PhpMyAdmin\Plugins\Schema\Dia\TableStatsDia;
use PhpMyAdmin\Plugins\Schema\Eps\TableStatsEps;
use PhpMyAdmin\Plugins\Schema\ExportRelationSchema;
use PhpMyAdmin\Plugins\Schema\Pdf\TableStatsPdf;
use PhpMyAdmin\Plugins\Schema\Svg\Svg;
use PhpMyAdmin\Plugins\Schema\Svg\TableStatsSvg;

/**
 * RelationStatsSvg Relation Schema Class
 *
 * Purpose of this class is to generate the SVG XML Document because
 * SVG defines the graphics in XML format which is used for representing
 * the database diagrams as vector image. This class actually helps
 *  in preparing SVG XML format.
 *
 * SVG XML is generated by using XMLWriter php extension and this class
 * inherits ExportRelationSchema class has common functionality added
 * to this class
 *
 * @package PhpMyAdmin
 * @name Svg_Relation_Schema
 */
class SvgRelationSchema extends ExportRelationSchema
{
    /** @var TableStatsDia[]|TableStatsEps[]|TableStatsPdf[]|TableStatsSvg[] */
    private $_tables = [];
    /** @var RelationStatsSvg[] Relations */
    private $_relations = [];
    private $_xMax = 0;
    private $_yMax = 0;
    private $_xMin = 100000;
    private $_yMin = 100000;
    private $_tablewidth;

    /**
     * Upon instantiation This starts writing the SVG XML document
     * user will be prompted for download as .svg extension
     *
     * @see PMA_SVG
     *
     * @param string $db database name
     */
    public function __construct($db)
    {
        parent::__construct($db, new Svg());

        $this->setShowColor(isset($_REQUEST['svg_show_color']));
        $this->setShowKeys(isset($_REQUEST['svg_show_keys']));
        $this->setTableDimension(isset($_REQUEST['svg_show_table_dimension']));
        $this->setAllTablesSameWidth(isset($_REQUEST['svg_all_tables_same_width']));

        $this->diagram->setTitle(
            sprintf(
                __('Schema of the %s database - Page %s'),
                $this->db,
                $this->pageNumber
            )
        );
        $this->diagram->SetAuthor('phpMyAdmin ' . PMA_VERSION);
        $this->diagram->setFont('Arial');
        $this->diagram->setFontSize(16);

        $alltables = $this->getTablesFromRequest();

        foreach ($alltables as $table) {
            if (! isset($this->_tables[$table])) {
                $this->_tables[$table] = new TableStatsSvg(
                    $this->diagram,
                    $this->db,
                    $table,
                    $this->diagram->getFont(),
                    $this->diagram->getFontSize(),
                    $this->pageNumber,
                    $this->_tablewidth,
                    $this->showKeys,
                    $this->tableDimension,
                    $this->offline
                );
            }

            if ($this->sameWide) {
                $this->_tables[$table]->width = &$this->_tablewidth;
            }
            $this->_setMinMax($this->_tables[$table]);
        }

        $border = 15;
        $this->diagram->startSvgDoc(
            $this->_xMax + $border,
            $this->_yMax + $border,
            $this->_xMin - $border,
            $this->_yMin - $border
        );

        $seen_a_relation = false;
        foreach ($alltables as $one_table) {
            $exist_rel = $this->relation->getForeigners($this->db, $one_table, '', 'both');
            if (! $exist_rel) {
                continue;
            }

            $seen_a_relation = true;
            foreach ($exist_rel as $master_field => $rel) {
                /* put the foreign table on the schema only if selected
                * by the user
                * (do not use array_search() because we would have to
                * to do a === false and this is not PHP3 compatible)
                */
                if ($master_field != 'foreign_keys_data') {
                    if (in_array($rel['foreign_table'], $alltables)) {
                        $this->_addRelation(
                            $one_table,
                            $this->diagram->getFont(),
                            $this->diagram->getFontSize(),
                            $master_field,
                            $rel['foreign_table'],
                            $rel['foreign_field'],
                            $this->tableDimension
                        );
                    }
                    continue;
                }

                foreach ($rel as $one_key) {
                    if (! in_array($one_key['ref_table_name'], $alltables)) {
                        continue;
                    }

                    foreach ($one_key['index_list'] as $index => $one_field) {
                        $this->_addRelation(
                            $one_table,
                            $this->diagram->getFont(),
                            $this->diagram->getFontSize(),
                            $one_field,
                            $one_key['ref_table_name'],
                            $one_key['ref_index_list'][$index],
                            $this->tableDimension
                        );
                    }
                }
            }
        }
        if ($seen_a_relation) {
            $this->_drawRelations();
        }

        $this->_drawTables();
        $this->diagram->endSvgDoc();
    }

    /**
     * Output RelationStatsSvg Document for download
     *
     * @return void
     */
    public function showOutput()
    {
        $this->diagram->showOutput($this->getFileName('.svg'));
    }

    /**
     * Sets X and Y minimum and maximum for a table cell
     *
     * @param TableStatsSvg $table The table
     *
     * @return void
     */
    private function _setMinMax($table)
    {
        $this->_xMax = max($this->_xMax, $table->x + $table->width);
        $this->_yMax = max($this->_yMax, $table->y + $table->height);
        $this->_xMin = min($this->_xMin, $table->x);
        $this->_yMin = min($this->_yMin, $table->y);
    }

    /**
     * Defines relation objects
     *
     * @see _setMinMax,Table_Stats_Svg::__construct(),
     *       PhpMyAdmin\Plugins\Schema\Svg\RelationStatsSvg::__construct()
     *
     * @param string  $masterTable    The master table name
     * @param string  $font           The font face
     * @param int     $fontSize       Font size
     * @param string  $masterField    The relation field in the master table
     * @param string  $foreignTable   The foreign table name
     * @param string  $foreignField   The relation field in the foreign table
     * @param boolean $tableDimension Whether to display table position or not
     *
     * @return void
     */
    private function _addRelation(
        $masterTable,
        $font,
        $fontSize,
        $masterField,
        $foreignTable,
        $foreignField,
        $tableDimension
    ) {
        if (! isset($this->_tables[$masterTable])) {
            $this->_tables[$masterTable] = new TableStatsSvg(
                $this->diagram,
                $this->db,
                $masterTable,
                $font,
                $fontSize,
                $this->pageNumber,
                $this->_tablewidth,
                false,
                $tableDimension
            );
            $this->_setMinMax($this->_tables[$masterTable]);
        }
        if (! isset($this->_tables[$foreignTable])) {
            $this->_tables[$foreignTable] = new TableStatsSvg(
                $this->diagram,
                $this->db,
                $foreignTable,
                $font,
                $fontSize,
                $this->pageNumber,
                $this->_tablewidth,
                false,
                $tableDimension
            );
            $this->_setMinMax($this->_tables[$foreignTable]);
        }
        $this->_relations[] = new RelationStatsSvg(
            $this->diagram,
            $this->_tables[$masterTable],
            $masterField,
            $this->_tables[$foreignTable],
            $foreignField
        );
    }

    /**
     * Draws relation arrows and lines
     * connects master table's master field to
     * foreign table's foreign field
     *
     * @see Relation_Stats_Svg::relationDraw()
     *
     * @return void
     */
    private function _drawRelations()
    {
        foreach ($this->_relations as $relation) {
            $relation->relationDraw($this->showColor);
        }
    }

    /**
     * Draws tables
     *
     * @see Table_Stats_Svg::Table_Stats_tableDraw()
     *
     * @return void
     */
    private function _drawTables()
    {
        foreach ($this->_tables as $table) {
            $table->tableDraw($this->showColor);
        }
    }
}
