<?php


namespace Drupal\neptune_sync\Data;


use Drupal\Core\Entity\EntityInterface;
use Drupal\neptune_sync\Utility\Helper;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\Entity\Term;

class EntityManager
{
    /**
     * @var array['Name' => id] special case of ['type' => 'ENTTYPE']
     */
    protected $entHash;

    public function __construct(){

        $this->entHash = array(
            "portfolios" => array('type' => 'node'),
            "bodies" => array('type' => 'node'),
            "outcome" => array('type' => 'taxonomy_term'),
            "program" => array('type' => 'taxonomy_term'));
    }

    /**
     * @param $entTitle
     * @param $entType
     * @param bool $canCreate
     * @return mixed|string|null
     */
    public function getEntityId($entTitle, $entType, $temp, bool $canCreate = false){
        if($this->entHash[$entType]['type'] == 'node') {                    //if Node
            $query = \Drupal::entityQuery('node')
                ->condition('title', $entTitle)
                ->condition('type', '$entType')
                ->execute();
        } else if ($this->entHash[$entType]['type'] == 'taxonomy_term'){    //if Tax
            $query = \Drupal::entityQuery('taxonomy_term')
                ->condition('name', $entTitle)
                ->condition('vid', '$entType')
                ->execute();
        } else {
            Helper::log("Err100: Something went seriously wrong", $event = true);
            return null;
        }

        if(count($query) == 0 && $canCreate) //ent doesnt exist
            $entId = $this->createEntity($entTitle, $entType);
        else
            $entId = reset($query);

        return $entId;
    }

    /**
     * @param $entTitle
     * @param $entType
     * @param bool $canCreate
     * @return String|null entity id
     */
    public function getEntityIdFromHash($entTitle, $entType, bool $canCreate = false){
        $entHash = $this->getEntTypeIdHash($entType);
        if(array_key_exists($entTitle, $entHash))
            return $entHash[$entTitle];
        else if ($canCreate){ //create then add to hash and relationship
            return $this->createEntity($entTitle, $entType);
        }
        return null;
    }

    /**
     * @variable NodeInterface $nodes
     * @param string $entName The node type of vocab name supported are
     * [portfolios, bodies, outcome, program]
     * @param String $entType "Taxonomy"|"Node"
     * @return String[] ['Ent title' => 'id'] of entName
     */
    protected function getEntTypeIdHash(String $entName){

        //if hash not built, build hash
        if(count($this->entHash[$entName]) > 1 ) { //> 1 as had hardcoded 'type' index
            Helper::log("creating hash for " . $entName);
            if($this->entHash[$entName]['type'] == "Node") {
                $nodes = $this->getAllNodeType($entName);
                foreach ($nodes as $node)
                    $this->entHash[$entName] += array($node->getTitle() => $node->id());
            } elseif($this->entHash[$entName]['type'] == "Taxonomy") {
                $terms = $this->getAllTaxonomyType($entName);
                foreach ($terms as $term)
                    $this->entHash[$entName] += array($term->getName(), $term->id());
            }
        }
        return $this->entHash[$entName];
    }

    /**
     * @param DrupalEntityExport $classModel
     * @return int|string|null
     * @throws \Drupal\Core\Entity\EntityStorageException
     */
    public function createEntity(DrupalEntityExport $classModel){

        $my_ent = EntityInterface::create(
            ['type' => $classModel->getSubType()]);                         //create
        foreach($classModel->getEntityArray() as $fieldKey => $fieldVal)    //save fields
            $my_ent->set($fieldKey, $fieldVal);                             //polymorphic
        $my_ent->enforceIsNew();                                            //marks as new
        $my_ent->save();                                                    //save

        return $my_ent->id();
    }

    /**
     * @param $nodeType
     * @return NodeInterface[]
     */
    public function getAllNodeType($nodeType){
        $nids = \Drupal::entityQuery('node')
            ->condition('type', $nodeType)
            ->execute();
        return Node::loadMultiple($nids);
    }

    /**
     * @param $vocabName
     * @return \Drupal\taxonomy\TermInterface[]
     */
    private function getAllTaxonomyType($vocabName){
        $tids = \Drupal::entityQuery('taxonomy_term')
            ->condition('vid', $vocabName)
            ->execute();
        return Term::loadMultiple($tids);
    }
}