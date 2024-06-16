<?php
/*
* This file is part of the Datapool CMS package.
* @package Datapool
* @author Carsten Wallenhauer <admin@datapool.info>
* @copyright 2023 to today Carsten Wallenhauer
* @license https://www.gnu.org/licenses/agpl-3.0.html AGPL-v3
*/
declare(strict_types=1);

namespace SourcePot\statistic;

class statistic implements \SourcePot\Datapool\Interfaces\Processor{
    
    private $oc;

    private $entryTable='';
    private $entryTemplate=array('Read'=>array('type'=>'SMALLINT UNSIGNED','value'=>'ALL_MEMBER_R','Description'=>'This is the entry specific Read access setting. It is a bit-array.'),
                                 'Write'=>array('type'=>'SMALLINT UNSIGNED','value'=>'ALL_CONTENTADMIN_R','Description'=>'This is the entry specific Read access setting. It is a bit-array.'),
                                 );

    public function __construct($oc){
        $this->oc=$oc;
        $table=str_replace(__NAMESPACE__,'',__CLASS__);
        $this->entryTable=mb_strtolower(trim($table,'\\'));
    }
    
    public function init(array $oc){
        $this->oc=$oc;
        $this->entryTemplate=$oc['SourcePot\Datapool\Foundation\Database']->getEntryTemplateCreateTable($this->entryTable,__CLASS__);
    }
    
    public function getEntryTable():string{return $this->entryTable;}

    /**
     * This method is the interface of this data processing class
     *
     * @param array $callingElementSelector Is the selector for the canvas element which called the method 
     * @param string $action Selects the requested process to be run  
     * @return bool TRUE the requested action exists or FALSE if not
     */
    public function dataProcessor(array $callingElementSelector=array(),string $action='info'){
        $callingElement=$this->oc['SourcePot\Datapool\Foundation\Database']->entryById($callingElementSelector,TRUE);
        switch($action){
            case 'run':
                if (empty($callingElement)){
                    return TRUE;
                } else {
                return $this->processStatistic($callingElement,$testRunOnly=FALSE);
                }
                break;
            case 'test':
                if (empty($callingElement)){
                    return TRUE;
                } else {
                    return $this->processStatistic($callingElement,$testRunOnly=TRUE);
                }
                break;
            case 'widget':
                if (empty($callingElement)){
                    return TRUE;
                } else {
                    return $this->getStatisticWidget($callingElement);
                }
                break;
            case 'settings':
                if (empty($callingElement)){
                    return TRUE;
                } else {
                    return $this->getStatisticSettings($callingElement);
                }
                break;
            case 'info':
                if (empty($callingElement)){
                    return TRUE;
                } else {
                    return $this->getStatisticInfo($callingElement);
                }
                break;
        }
        return FALSE;
    }

    private function getStatisticWidget($callingElement){
        $S=$this->oc['SourcePot\Datapool\Tools\MiscTools']->getSeparator();
        $html='';
        // invoice widget
        $html.=$this->oc['SourcePot\Datapool\Foundation\Container']->container('Statistic','generic',$callingElement,array('method'=>'getStatisticWidgetHtml','classWithNamespace'=>__CLASS__),array());
        return $html;
    }

    private function getStatisticInfo($callingElement){
        $matrix=array();
        $matrix['']['value']='';
        $html=$this->oc['SourcePot\Datapool\Tools\HTMLbuilder']->table(array('matrix'=>$matrix,'hideHeader'=>TRUE,'hideKeys'=>FALSE,'keep-element-content'=>TRUE,'caption'=>'Info'));
        return $html;
    }
       
    public function getStatisticWidgetHtml($arr){
        if (!isset($arr['html'])){$arr['html']='';}
        // command processing
        $result=array();
        $formData=$this->oc['SourcePot\Datapool\Foundation\Element']->formProcessing(__CLASS__,__FUNCTION__);
        if (isset($formData['cmd']['run'])){
            $result=$this->processStatistic($arr['selector'],FALSE);
        } else if (isset($formData['cmd']['test'])){
            $result=$this->processStatistic($arr['selector'],TRUE);
        }
        // build html
        $btnArr=array('tag'=>'input','type'=>'submit','callingClass'=>__CLASS__,'callingFunction'=>__FUNCTION__);
        $matrix=array();
        $btnArr['value']='Check';
        $btnArr['key']=array('test');
        $matrix['Commands']['Test']=$btnArr;
        $btnArr['value']='Process statistic';
        $btnArr['key']=array('run');
        $matrix['Commands']['Run']=$btnArr;
        $arr['html'].=$this->oc['SourcePot\Datapool\Tools\HTMLbuilder']->table(array('matrix'=>$matrix,'style'=>'clear:left;','hideHeader'=>TRUE,'hideKeys'=>TRUE,'keep-element-content'=>TRUE,'caption'=>'Statistic'));
        foreach($result as $caption=>$matrix){
            $arr['html'].=$this->oc['SourcePot\Datapool\Tools\HTMLbuilder']->table(array('matrix'=>$matrix,'hideHeader'=>FALSE,'hideKeys'=>FALSE,'keep-element-content'=>TRUE,'caption'=>$caption));
        }
        $arr['wrapperSettings']=array('style'=>array('width'=>'fit-content'));
        return $arr;
    }
    
    private function getStatisticSettings($callingElement){
        $html='';
        if ($this->oc['SourcePot\Datapool\Foundation\Access']->isContentAdmin()){
            $html.=$this->oc['SourcePot\Datapool\Foundation\Container']->container('Statistic entries settings','generic',$callingElement,array('method'=>'getStatisticSettingsHtml','classWithNamespace'=>__CLASS__),array());
        }
        return $html;
    }
    
    public function getStatisticSettingsHtml($arr){
        if (!isset($arr['html'])){$arr['html']='';}
        $arr['html'].=$this->processingParams($arr['selector']);
        $arr['html'].=$this->processingRules($arr['selector']);
        //$selectorMatrix=$this->oc['SourcePot\Datapool\Tools\MiscTools']->arr2matrix($callingElement['Content']['Selector']);
        //$arr['html'].=$this->oc['SourcePot\Datapool\Tools\HTMLbuilder']->table(array('matrix'=>$selectorMatrix,'style'=>'clear:left;','hideHeader'=>TRUE,'hideKeys'=>TRUE,'keep-element-content'=>TRUE,'caption'=>'Selector used for mapping'));
        return $arr;
    }

    private function processingParams($callingElement){
        $contentStructure=array('Target success'=>array('method'=>'canvasElementSelect','excontainer'=>TRUE),
                                'Target failure'=>array('method'=>'canvasElementSelect','excontainer'=>TRUE),
                                'Rules match<br/>sample probability'=>array('method'=>'select','excontainer'=>TRUE,'value'=>100,'options'=>array(100=>'100%',90=>'90%',80=>'80%',70=>'70%',60=>'60%',50=>'50%',40=>'40%',30=>'30%',20=>'20%',10=>'10%',5=>'5%',2=>'2%',1=>'1%'),'keep-element-content'=>TRUE),
                                'Rules no match<br/>sample probability'=>array('method'=>'select','excontainer'=>TRUE,'value'=>5,'options'=>array(100=>'100%',90=>'90%',80=>'80%',70=>'70%',60=>'60%',50=>'50%',40=>'40%',30=>'30%',20=>'20%',10=>'10%',5=>'5%',2=>'2%',1=>'1%'),'keep-element-content'=>TRUE),
                                'Save'=>array('method'=>'element','tag'=>'button','element-content'=>'&check;','keep-element-content'=>TRUE,'value'=>'string'),
                                );
        // get selctor
        $arr=$this->oc['SourcePot\Datapool\Foundation\DataExplorer']->callingElement2arr(__CLASS__,__FUNCTION__,$callingElement,TRUE);
        $arr['selector']=$this->oc['SourcePot\Datapool\Foundation\Database']->entryByIdCreateIfMissing($arr['selector'],TRUE);
        // form processing
        $formData=$this->oc['SourcePot\Datapool\Foundation\Element']->formProcessing(__CLASS__,__FUNCTION__);
        $elementId=key($formData['val']);
        if (isset($formData['cmd'][$elementId])){
            $arr['selector']['Content']=$formData['val'][$elementId]['Content'];
            $arr['selector']=$this->oc['SourcePot\Datapool\Foundation\Database']->updateEntry($arr['selector'],TRUE);
        }
        // get HTML
        $arr['canvasCallingClass']=$callingElement['Folder'];
        $arr['contentStructure']=$contentStructure;
        $arr['caption']='Statistic control: select forwarding targets and probabilities';
        $arr['noBtns']=TRUE;
        $row=$this->oc['SourcePot\Datapool\Tools\HTMLbuilder']->entry2row($arr,FALSE,TRUE);
        if (empty($arr['selector']['Content'])){$row['trStyle']=array('background-color'=>'#a00');}
        $matrix=array('Parameter'=>$row);
        return $this->oc['SourcePot\Datapool\Tools\HTMLbuilder']->table(array('matrix'=>$matrix,'style'=>'clear:left;','hideHeader'=>FALSE,'hideKeys'=>TRUE,'keep-element-content'=>TRUE,'caption'=>$arr['caption']));
    }
    
    private function processingRules($callingElement){
        $contentStructure=array('...'=>array('method'=>'select','excontainer'=>TRUE,'value'=>'||','options'=>array('&&'=>'AND','||'=>'OR'),'keep-element-content'=>TRUE),
                                'Property'=>array('method'=>'keySelect','excontainer'=>TRUE,'value'=>'Folder','addSourceValueColumn'=>FALSE),
                                'Property data type'=>array('method'=>'select','excontainer'=>TRUE,'value'=>'string','options'=>$this->oc['SourcePot\Datapool\Tools\MiscTools']->getDataTypes(),'keep-element-content'=>TRUE),
                                'Condition'=>array('method'=>'select','excontainer'=>TRUE,'value'=>'strpos','options'=>$this->oc['SourcePot\Datapool\Tools\MiscTools']->getConditions(),'keep-element-content'=>TRUE),
                                'Compare value'=>array('method'=>'element','tag'=>'input','type'=>'text','placeholder'=>'P532132WEDE','excontainer'=>TRUE),
                                );
        $contentStructure['Property']+=$callingElement['Content']['Selector'];
        $arr=$this->oc['SourcePot\Datapool\Foundation\DataExplorer']->callingElement2arr(__CLASS__,__FUNCTION__,$callingElement,TRUE);
        $arr['canvasCallingClass']=$callingElement['Folder'];
        $arr['contentStructure']=$contentStructure;
        $arr['caption']='Statistic filter rules: defines entry filter for manual checking';
        $html=$this->oc['SourcePot\Datapool\Tools\HTMLbuilder']->entryListEditor($arr);
        return $html;
    }

    private function processStatistic($callingElement,$testRun=FALSE){
        $base=array('processingparams'=>array(),'processingrules'=>array());
        $base=$this->oc['SourcePot\Datapool\Foundation\DataExplorer']->callingElement2settings(__CLASS__,__FUNCTION__,$callingElement,$base);
        // add to base canvas elements->array('EntryId'=>'Name')
        $canvasElements=$this->oc['SourcePot\Datapool\Foundation\DataExplorer']->getCanvasElements($callingElement['Folder']);
        foreach($canvasElements as $index=>$canvasElement){
            $base[$canvasElement['EntryId']]=$canvasElement['Content']['Style']['Text'];
        }
        // loop through source entries and parse these entries
        $this->oc['SourcePot\Datapool\Foundation\Database']->resetStatistic();
        $result=array('Statistic'=>array());
        // loop through entries
        $params=current($base['processingparams']);
        foreach($this->oc['SourcePot\Datapool\Foundation\Database']->entryIterator($callingElement['Content']['Selector'],TRUE,'Read') as $sourceEntry){
            $result=$this->processEntry($base,$sourceEntry,$result,$testRun);
        }
        $result['Statistics']=$this->oc['SourcePot\Datapool\Foundation\Database']->statistic2matrix();
        $result['Statistics']['Script time']=array('Value'=>date('Y-m-d H:i:s'));
        $result['Statistics']['Time consumption [msec]']=array('Value'=>round((hrtime(TRUE)-$base['Script start timestamp'])/1000000));
        return $result;
    }
    
    private function processEntry($base,$sourceEntry,$result,$testRun){
        $params=current($base['processingparams']);
        // check rule match
        $ruleMatch=NULL;
        $flatSourceEntry=$this->oc['SourcePot\Datapool\Tools\MiscTools']->arr2flat($sourceEntry);
        foreach($base['processingrules'] as $ruleIndex=>$rule){
            $rule['Content']['ruleIndex']=$this->oc['SourcePot\Datapool\Foundation\Database']->getOrderedListIndexFromEntryId($ruleIndex);
        }
        
        return $result;
    }
}
?>