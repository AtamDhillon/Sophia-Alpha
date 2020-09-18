<?php
namespace Drupal\neptune_sync\Data;

use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\TypedData\Exception\MissingDataException;
use Drupal\neptune_sync\Querier\QueryBuilder;
use Drupal\neptune_sync\Querier\QueryManager;
use Drupal\node\Entity\Node;
use Drupal\neptune_sync\Utility\Helper;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\Entity\Term;

class CharacterSheetManager
{
    protected $body;
    protected $query_mgr;
    protected $ent_mgr;
    protected $countSkip;
    protected $countupdated;

    public function __construct(){
        $this->body = new CharacterSheet();
        $this->query_mgr = new QueryManager();
        $this->ent_mgr = new EntityManager();
        $this->countSkip = 0;
        $this->countupdated = 0;
    }

    /**
     * @param NodeInterface $node
     * @param bool $bulkOperation
     * @throws EntityStorageException
     * @TODO remove N/A once all neptune queries are complete and tested
     */
    public function updateCharacterSheet(NodeInterface $node, Bool $bulkOperation = false){


        /** Flipchart keys
         *  E 129| I 131 | R 133 | * 134 | ℗ 144 | X 145
         */

        /** PSA 1999
         * @TODO what is M (refer to tax terms?) make this more readable
         * @TODO remove this
         * this also doesnt work
         */
       /* $query = QueryBuilder::checkPsAct($node);
        if($this->evaluate($this->query_mgr->runCustomQuery($query)))
            $this->body->setPsAct(100); //psa = yay
        else
            $this->body->setPsAct(101); //psa = no
*/
        /** Character sheet booleans
         *  NA 152
         * @TODO these are currently just defaulted values, these need review and hooking up
         */

        $this->processPortfolio($node, $bulkOperation);
        $this->processBodyType($node);
        $this->processFinClass($node);
        $this->processLegislation($node);
        $this->processEcoSector($node);
        $this->processEmploymentType($node);
        $this->processCooperativeRelationships($node);

        //kint($this->body->getLegislations());
        $this->updateNode($node);
        //$this->testfunc($node);

    }

    public function updateAllCharacterSheets(){

        //TODO use this var instead of loading a node in update func
        $bodies = $this->ent_mgr->getAllNodeType("bodies");
        foreach($bodies as $bodyItr){
            $this->body = new CharacterSheet();
            $this->updateCharacterSheet($bodyItr);
        }
    }

    /**
     * @param NodeInterface $node
     * @param bool $bulkOperation
     * Logic:
     *      -Get and execute query to get portfolio from body
     *      -Decode json result from query to php object
     *      -Get nid of portfolio from the portfolios label
     *      -add nid as entity reference to body
     */
    private function processPortfolio(NodeInterface $node, Bool $bulkOperation){

        $query = QueryBuilder::getBodyPortfolio($node);
        $jsonResult = $this->query_mgr->runCustomQuery($query);
        $jsonObject = json_decode($jsonResult);
        if(count($jsonObject->{'results'}->{'bindings'}) == 0)
            return;
        $portfolioLabel = $jsonObject->{'results'}->{'bindings'}[0]->{'portlabel'}->{'value'};
        $portNid = null;

        if($portfolioLabel && !$bulkOperation) { //single execution, poll RDS
            $portNid = $this->ent_mgr->getEntityId(
                $portfolioLabel, 'portfolios', $canCreate = false );
        } elseif ($portfolioLabel){ //part of a bulk execution, use pre-filled hash table
            $portfolioHash = $this->ent_mgr->getEntityIdFromHash('portfolios', 'Node');
            $portNid = $portfolioHash[$portfolioLabel];
        }
        if($portNid)
            $this->body->setPortfolio($portNid);
    }

    /**
     * @param NodeInterface $node
     * TODO make bulk maybe? examine diff between this and legis func
     */
    private function processLegislation(NodeInterface $node){

        $query = QueryBuilder::getBodyLegislation($node);
        $jsonResult = $this->query_mgr->runCustomQuery($query);
        $jsonObject = json_decode($jsonResult);

        foreach ( $jsonObject->{'results'}->{'bindings'} as $binding){
            $legislationNid = $this->ent_mgr->getEntityId(
                $binding->{'legislationLabel'}->{'value'},
                'portfolios', $canCreate = false );

            $this->body->addLegislations($legislationNid);
        }
    }

    /** Body Type
     * @param NodeInterface $node
     *       Non-corporate Commonwealth entity 87 | Corporate Commonwealth entity 88 | Commonwealth company 90 |
     * @todo add comcompany, maybe as default?  */
    private function processBodyType(NodeInterface $node){

        $vals =['NonCorporateCommonwealthEntity' => 87, 'CorporateCommonwealthEntity' => 88,
            "NEPTUNEFIELD" /* can this be defaulted? */=> 90, 'NA' => 153];
        $res = $this->check_property($vals, $node);
        if(!$res)
            $res = $vals['NA'];
        $this->body->setTypeOfBody($res);
    }

    /**
     * @param NodeInterface $node
     * Economic Sector
     *      General Government Sector 91 | Public Financial Corporation 94 | Public Nonfinancial Corporation 92 | N/A 149
     * @TODO add other terms */
    private function processEcoSector(NodeInterface $node){

        $vals =['GeneralGovernmentSectorEntity'=> 91, 'NEPTUNEFIELD' => 94, 'NEPTUNEFIELD' => 92, 'NA' => 149] ;
        $res = $this->check_property($vals, $node);
        if($res == null)
            $res = $vals['NA'];
        $this->body->setEcoSector($res);
    }

    /**
     * @param NodeInterface $node
     * Financial classification
     *      Material 95, Government Business Enterprise 96, Non-Material 109 */
    private function processFinClass(NodeInterface $node){

        $vals =['MaterialEntity' => 95,  'CommonwealthCompany' => 96, 'NonMaterialEntity' => 109];
        $res = $this->check_property($vals, $node);
        if($res == null)
            $res = $vals['NonMaterialEntity'];
        $this->body->setFinClass($res);
    }

    /**
     * @param NodeInterface $node
     * Employment type
     *      Public Service Act 1999 123 | Non-Public Service Act 1999 124 | Both 125 | Parliamentary Service Act 1999 126 | N/A 151
     * @TODO everything */
    private function processEmploymentType(NodeInterface $node){

        $vals =['NEPTUNEFIELD' => 123, 'NEPTUNEFIELD' => 124, 'NEPTUNEFIELD' => 125, 'NEPTUNEFIELD' => 126, 'NA' => 151];
        $res = null; //this is killing it
        if($res == null)
            $res = $vals['NA'];
        $this->body->setEmploymentType($res);
    }

    private function processCooperativeRelationships(
        NodeInterface $node,  Bool $bulkOperation = false){

        $query = QueryBuilder::getCooperativeRelationships($node);
        $json = $this->query_mgr->runCustomQuery($query);
        $obj = json_decode($json);

        //no results
        if (count($obj->{'results'}->{'bindings'}) == 0) {
            //do we need to do anything?
            return;
        }

        //map results
        for ($c = 0; $c < count($obj->{'results'}->{'bindings'}); $c++) {
            $res = [
                'program' => $obj->{'results'}->{'bindings'}->{'progLabel'}->{'value'},
                'outcome' => $obj->{'results'}->{'bindings'}->{'outcomeLabel'}->{'value'},
                'receiver'=> $obj->{'results'}->{'bindings'}->{'ent2Label'}->{'value'}
            ];

            $relationship = new CooperativeRelationship();

            //if bulk
            $relationship->setOwner($node->id());
            $relationship->setOutcome($this->ent_mgr->getEntityIdFromHash(
                $res['outcome'], 'outcome', true));
            $relationship->setProgram($this->ent_mgr->getEntityIdFromHash(
                $res['program'], 'program', true));
            $relationship->setReceiver($this->ent_mgr->getEntityIdFromHash(
                $res['receiver'], 'receiver', true));
        }
    }

    /**
     * @param $res string result of an ASK query in json
     * @return mixed returns the a php boolean on the results of a ASK query
     */
    private function evaluate($res){
        $obj = json_decode($res);
        return $obj->{'boolean'};
    }

    /**
     * @param $vals array list of neptune label strings to attempt to match
     * @param $node
     * @return false|mixed
     *
     * Checks if a (Var) label can be found from a passed in nodes label
     */
    private function check_property($vals, $node){
        foreach (array_keys($vals) as $val){

            $query = QueryBuilder::checkAskBody($node, $val);
            $json = $this->query_mgr->runCustomQuery($query);
            if ($this->evaluate($json))
                return $vals[$val];
        }
        return false;
    }

    /**
     * @param NodeInterface $node
     * @throws EntityStorageException|MissingDataException
     */
    private function updateNode(NodeInterface $node){

        /** @var NodeInterface $editNode */
        $editNode = Node::load($node->id());
        $toUpdate = false;

        if($this->shouldUpdate($editNode, "field_portfolio",
            $this->body->getPortfolio())) {

            $toUpdate = true;
            $editNode->field_portfolio =
                array(['target_id' => $this->body->getPortfolio()]);
        }
        if($this->shouldUpdate($editNode, "field_type_of_body",
            $this->body->getTypeOfBody())) {

            $toUpdate = true;
            $editNode->field_type_of_body =
                array(['target_id' => $this->body->getTypeOfBody()]);
        }
        if($this->shouldUpdate($editNode, "field_economic_sector",
            $this->body->getEcoSector())) {

            $toUpdate = true;
            $editNode->field_economic_sector =
                array(['target_id' => $this->body->getEcoSector()]);
        }
        if($this->shouldUpdate($editNode, "field_financial_classification",
            $this->body->getFinClass())) { //@todo multiplicity

            $toUpdate = true;
            $editNode->field_financial_classification =
                array(['target_id' => $this->body->getFinClass()]);
        }
        if($this->shouldUpdate($editNode, "field_employment_arrangements",
            $this->body->getEmploymentType())) {

            $toUpdate = true;
            $editNode->field_employment_arrangements =
                array(['target_id' => $this->body->getEmploymentType()]);
        }
        if($this->shouldUpdate($editNode, "field_enabling_legislation_and_o",
            $this->body->getLegislations())){

            $toUpdate = true;//clear current vals
            $editNode->field_enabling_legislation_and_o = array();
            foreach($this->body->getLegislations() as $nid)  //@todo multiplicity
                $editNode->field_enabling_legislation_and_o[] = ['target_id' => $nid];
        }

        //flipkeys

        //default value these nodes until value is complete
        $editNode->field_s35_3_pgpa_act_apply = array(['target_id' => 152]);
        $editNode->field_employed_under_the_ps_act = array(['target_id' => 152]);
        $editNode->field_reporting_variation = array(['target_id' => 152]);
        $editNode->field_cp_tabled = array(['target_id' => 152]);

        /** todo
         * field_accountable_authority_or_g
         * field_ink
         * field_reporting_arrangements
         */

        //$editNode->field_employed_under_the_ps_act = array(['target_id' => $this->body->getPsAct()]);
        if($toUpdate) {
            $editNode->setNewRevision();
            $editNode->setRevisionUserId(47);
            $editNode->save();
            $this->countupdated++;
            Helper::log("updating " . $node->id() . " | Updated: " .
                $this->countupdated . "\tSkipped: " . $this->countSkip, true);
        } else {
            $this->countSkip++;
            Helper::log("skipping " . $node->id(), true);
        }
    }

    //how do we handle a removal

    /**
     * @param NodeInterface $editNode
     * @param String $nodeField
     * @param String|String[] $compVal
     * @return bool
     * @throws MissingDataException
     * TODO rename $compVal to neptune val
     */
    private function shouldUpdate (NodeInterface $editNode, String $nodeField, $compVal){

        Helper::log("shouldUpdate () attempting " . $nodeField);

        //multi field | if either field is a multi val
        Helper::log("comp val count:" . count($compVal), false, $compVal);
        Helper::log("node vals count:" . count($editNode->get($nodeField)->getValue()),
            false, array_merge(...$editNode->get($nodeField)->getValue()));

        $nodeFieldArr = array();
        foreach ($editNode->get($nodeField)->getValue() as $val)
           $nodeFieldArr[] = $val['target_id'];

        Helper::log("modded node val", false, $nodeFieldArr);

        //multi
        if (is_array($compVal) || count($editNode->get($nodeField)->getValue()) > 1) {
            Helper::log("shouldUpdate () multi match " . $nodeField);
            if ($nodeFieldArr != $compVal ||
                count($compVal) != count($editNode->get($nodeField)->getValue())){
                Helper::log("UPDATE FIELD!0");
                return true;
            }
        } else { //single field
            Helper::log("shouldUpdate () single match " . $nodeField);

            //if node has no value and neptune does
            if ($editNode->get($nodeField)->first() == null)
                if ($compVal) {
                    Helper::log("UPDATE FIELD!1");
                    return true;
                } else
                    return false;
            //if node has value and neptune doesnt
            else if(!$compVal && $editNode->get($nodeField)->first() != null) {
                Helper::log("UPDATE FIELD!3");
                return true;
            }

            $array = $editNode->get($nodeField)->first()->getValue();

            //if neptune has a value and neptune does not equal node
            if ($compVal && reset($array) != $compVal) {
                Helper::log("UPDATE FIELD!2");
                return true;
            }
        }
        return false;
    }
}