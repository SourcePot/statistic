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

    private $casesToProcess=array();
    
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
     *
     * @return string|bool Return the html-string or TRUE callingElement does not exist
     */
    public function dataProcessor(array $callingElementSelector=array(),string $action='info'){
        $callingElement=$this->oc['SourcePot\Datapool\Foundation\Database']->entryById($callingElementSelector,TRUE);
        if (empty($callingElement)){
            return TRUE;
        } else {
            return match($action){
                'run'=>$this->processStatistic($callingElement,$testRunOnly=FALSE),
                'test'=>$this->processStatistic($callingElement,$testRunOnly=TRUE),
                'widget'=>$this->getStatisticWidget($callingElement),
                'settings'=>$this->getStatisticSettings($callingElement),
                'info'=>$this->getStatisticInfo($callingElement),
            };
        }
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
            $html.=$this->oc['SourcePot\Datapool\Foundation\Container']->container('Statistic entries rules','generic',$callingElement,array('method'=>'getStatisticRulesHtml','classWithNamespace'=>__CLASS__),array());
        }
        return $html;
    }
    
    public function getStatisticSettingsHtml($arr){
        if (!isset($arr['html'])){$arr['html']='';}
        $arr['html'].=$this->processingParamsCosts($arr['selector']);
        $arr['html'].=$this->processingParamsCases($arr['selector']);
        return $arr;
    }

    public function getStatisticRulesHtml($arr){
        if (!isset($arr['html'])){$arr['html']='';}
        $arr['html'].=$this->processingRules($arr['selector']);
        return $arr;
    }

    private function processingParamsCosts($callingElement){
        $contentStructure=array('Cost records'=>array('method'=>'canvasElementSelect','excontainer'=>FALSE),
                                'Case reference '=>array('method'=>'keySelect','excontainer'=>TRUE,'value'=>'Folder','standardColumsOnly'=>TRUE,'showSample'=>TRUE,'addSourceValueColumn'=>FALSE,'keep-element-content'=>FALSE),
                                'Cost record date'=>array('method'=>'keySelect','excontainer'=>TRUE,'value'=>'Content|[]|Rechnungsdatum|[]|System short','showSample'=>TRUE,'addSourceValueColumn'=>FALSE,'keep-element-content'=>FALSE),
                                'Cost record amount'=>array('method'=>'keySelect','excontainer'=>TRUE,'value'=>'Content|[]|Endbetrag|[]|Amount','showSample'=>TRUE,'addSourceValueColumn'=>FALSE,'keep-element-content'=>FALSE),
                                'Cost record type'=>array('method'=>'keySelect','excontainer'=>TRUE,'value'=>'Content|[]|Gebührenkategorie','showSample'=>TRUE,'addSourceValueColumn'=>FALSE,'keep-element-content'=>FALSE),
                                'Category costs'=>array('method'=>'keySelect','excontainer'=>TRUE,'value'=>'Content|[]|Zahlungsempfänger','showSample'=>TRUE,'addSourceValueColumn'=>FALSE,'keep-element-content'=>FALSE),
                                );
        // get selctor
        $arr=$this->oc['SourcePot\Datapool\Foundation\DataExplorer']->callingElement2arr(__CLASS__,__FUNCTION__,$callingElement,TRUE);
        $arr['selector']=$this->oc['SourcePot\Datapool\Foundation\Database']->entryByIdCreateIfMissing($arr['selector'],TRUE);
        // form processing
        $formData=$this->oc['SourcePot\Datapool\Foundation\Element']->formProcessing(__CLASS__,__FUNCTION__);
        $elementId=key($formData['val']);
        if (!empty($formData['val'])){
            $arr['selector']['Content']=$formData['val'][$elementId]['Content'];
            $arr['selector']=$this->oc['SourcePot\Datapool\Foundation\Database']->updateEntry($arr['selector'],TRUE);
        }
        // current params
        $base=$this->oc['SourcePot\Datapool\Foundation\DataExplorer']->callingElement2settings(__CLASS__,__FUNCTION__,$callingElement,array());
        $params=current($base['processingparamscosts']);
        if (isset($params['Content']['Cost records'])){
            $contentStructure['Case reference ']+=$base['entryTemplates'][$params['Content']['Cost records']];
            $contentStructure['Cost record date']+=$base['entryTemplates'][$params['Content']['Cost records']];
            $contentStructure['Cost record amount']+=$base['entryTemplates'][$params['Content']['Cost records']];
            $contentStructure['Cost record type']+=$base['entryTemplates'][$params['Content']['Cost records']];
            $contentStructure['Category costs']+=$base['entryTemplates'][$params['Content']['Cost records']];
        }
        // get HTML
        $arr['canvasCallingClass']=$callingElement['Folder'];
        $arr['contentStructure']=$contentStructure;
        $arr['caption']='Cost records control';
        $arr['noBtns']=TRUE;
        $row=$this->oc['SourcePot\Datapool\Tools\HTMLbuilder']->entry2row($arr,FALSE,TRUE);
        if (empty($arr['selector']['Content'])){$row['trStyle']=array('background-color'=>'#a00');}
        $matrix=array('Parameter'=>$row);
        return $this->oc['SourcePot\Datapool\Tools\HTMLbuilder']->table(array('matrix'=>$matrix,'style'=>'clear:left;','hideHeader'=>FALSE,'hideKeys'=>TRUE,'keep-element-content'=>TRUE,'caption'=>$arr['caption']));
    }
    
    private function processingParamsCases($callingElement){
        $contentStructure=array('Case records'=>array('method'=>'canvasElementSelect','excontainer'=>FALSE),
                                'Case reference'=>array('method'=>'keySelect','value'=>'Name','standardColumsOnly'=>TRUE,'showSample'=>TRUE,'addSourceValueColumn'=>FALSE,'excontainer'=>TRUE,'keep-element-content'=>FALSE),
                                'Base date'=>array('method'=>'keySelect','excontainer'=>TRUE,'value'=>'Content|[]|Zuerkannter Anmeldetag|[]|System short','showSample'=>TRUE,'addSourceValueColumn'=>FALSE,'keep-element-content'=>FALSE),
                                'Category cases'=>array('method'=>'keySelect','excontainer'=>TRUE,'value'=>'Content|[]|Codepfad|[]|FhI','showSample'=>TRUE,'addSourceValueColumn'=>FALSE,'keep-element-content'=>FALSE),
                                'Bins'=>array('method'=>'select','excontainer'=>TRUE,'value'=>'yearly','options'=>array('yearly'=>'Yearly','monthly'=>'Monthly'),'keep-element-content'=>FALSE),
                                );
        // get selctor
        $arr=$this->oc['SourcePot\Datapool\Foundation\DataExplorer']->callingElement2arr(__CLASS__,__FUNCTION__,$callingElement,TRUE);
        $arr['selector']=$this->oc['SourcePot\Datapool\Foundation\Database']->entryByIdCreateIfMissing($arr['selector'],TRUE);
        // form processing
        $formData=$this->oc['SourcePot\Datapool\Foundation\Element']->formProcessing(__CLASS__,__FUNCTION__);
        $elementId=key($formData['val']);
        if (!empty($formData['val'])){
            $arr['selector']['Content']=$formData['val'][$elementId]['Content'];
            $arr['selector']=$this->oc['SourcePot\Datapool\Foundation\Database']->updateEntry($arr['selector'],TRUE);
        }
        // current params
        $base=$this->oc['SourcePot\Datapool\Foundation\DataExplorer']->callingElement2settings(__CLASS__,__FUNCTION__,$callingElement,array());
        $params=current($base['processingparamscases']);
        if (isset($params['Content']['Case records'])){
            $contentStructure['Case reference']+=$base['entryTemplates'][$params['Content']['Case records']];
            $contentStructure['Base date']+=$base['entryTemplates'][$params['Content']['Case records']];
            $contentStructure['Category cases']+=$base['entryTemplates'][$params['Content']['Case records']];
        }
        // get HTML
        $arr['canvasCallingClass']=$callingElement['Folder'];
        $arr['contentStructure']=$contentStructure;
        $arr['caption']='Case records control';
        $arr['noBtns']=TRUE;
        $row=$this->oc['SourcePot\Datapool\Tools\HTMLbuilder']->entry2row($arr,FALSE,TRUE);
        if (empty($arr['selector']['Content'])){$row['trStyle']=array('background-color'=>'#a00');}
        $matrix=array('Parameter'=>$row);
        return $this->oc['SourcePot\Datapool\Tools\HTMLbuilder']->table(array('matrix'=>$matrix,'style'=>'clear:left;','hideHeader'=>FALSE,'hideKeys'=>TRUE,'keep-element-content'=>TRUE,'caption'=>$arr['caption']));
    }
    
    private function processingRules($callingElement){
        $contentStructure=array('Key A'=>array('method'=>'keySelect','excontainer'=>TRUE,'value'=>'Content|[]|Codepfad|[]|FhI','addSourceValueColumn'=>TRUE,'keep-element-content'=>FALSE),
                                'Data type A'=>array('method'=>'select','excontainer'=>TRUE,'value'=>'string','options'=>$this->oc['SourcePot\Datapool\Tools\MiscTools']->getDataTypes(),'keep-element-content'=>TRUE),
                                'Condition A'=>array('method'=>'select','excontainer'=>TRUE,'value'=>'strpos','options'=>$this->oc['SourcePot\Datapool\Tools\MiscTools']->getConditions(),'keep-element-content'=>TRUE),
                                'Compare value A'=>array('method'=>'element','tag'=>'input','type'=>'text','placeholder'=>'244','excontainer'=>TRUE),
                                'Operation'=>array('method'=>'select','excontainer'=>TRUE,'value'=>'||','options'=>array('&&'=>'AND','||'=>'OR'),'keep-element-content'=>TRUE),
                                'Key B'=>array('method'=>'keySelect','excontainer'=>TRUE,'value'=>'useValue','addSourceValueColumn'=>TRUE,'keep-element-content'=>FALSE),
                                'Data type B'=>array('method'=>'select','excontainer'=>TRUE,'value'=>'string','options'=>$this->oc['SourcePot\Datapool\Tools\MiscTools']->getDataTypes(),'keep-element-content'=>TRUE),
                                'Condition B'=>array('method'=>'select','excontainer'=>TRUE,'value'=>'strpos','options'=>$this->oc['SourcePot\Datapool\Tools\MiscTools']->getConditions(),'keep-element-content'=>TRUE),
                                'Compare value B'=>array('method'=>'element','tag'=>'input','type'=>'text','placeholder'=>'','excontainer'=>TRUE),
                                );
        // current params
        $base=$this->oc['SourcePot\Datapool\Foundation\DataExplorer']->callingElement2settings(__CLASS__,__FUNCTION__,$callingElement,array());
        $params=current($base['processingparamscases']);
        if (isset($params['Content']['Case records'])){
            $contentStructure['Key A']+=$base['entryTemplates'][$params['Content']['Case records']];
            $contentStructure['Key B']+=$base['entryTemplates'][$params['Content']['Case records']];
        }
        $arr=$this->oc['SourcePot\Datapool\Foundation\DataExplorer']->callingElement2arr(__CLASS__,__FUNCTION__,$callingElement,TRUE);
        $arr['canvasCallingClass']=$callingElement['Folder'];
        $arr['contentStructure']=$contentStructure;
        $arr['caption']='Statistic filter rules: defines entry filter for manual checking';
        $html=$this->oc['SourcePot\Datapool\Tools\HTMLbuilder']->entryListEditor($arr);
        return $html;
    }

    private function processStatistic($callingElement,$testRun=FALSE){
        $base=array('processingparamscosts'=>array(),'processingparamscases'=>array(),'processingrules'=>array());
        $base=$this->oc['SourcePot\Datapool\Foundation\DataExplorer']->callingElement2settings(__CLASS__,__FUNCTION__,$callingElement,$base);
        $paramsCosts=current($base['processingparamscosts'])['Content'];
        $paramsCases=current($base['processingparamscases'])['Content'];
        $base['costsSelector']=$base['entryTemplates'][$paramsCosts['Cost records']];
        $base['casesSelector']=$base['entryTemplates'][$paramsCases['Case records']];
        // loop through source entries and parse these entries
        $this->oc['SourcePot\Datapool\Foundation\Database']->resetStatistic();
        $result=array('Statistic'=>array('Entries'=>array('value'=>0)));
        // loop through entries
        foreach($this->oc['SourcePot\Datapool\Foundation\Database']->entryIterator($base['casesSelector'],TRUE,'Read') as $sourceEntry){
            $result['Statistic']['Entries']['value']++;
            $result=$this->processCase($base,$sourceEntry,$result,$testRun);
        }
        $result['Statistics']=$this->oc['SourcePot\Datapool\Foundation\Database']->statistic2matrix();
        $result['Statistics']['Script time']=array('Value'=>date('Y-m-d H:i:s'));
        $result['Statistics']['Time consumption [msec]']=array('Value'=>round((hrtime(TRUE)-$base['Script start timestamp'])/1000000));

        $this->oc['SourcePot\Datapool\Tools\MiscTools']->arr2file($this->casesToProcess);

        return $result;
    }
    
    private function processCase($base,$sourceEntry,$result,$testRun)
    {
        $flatCaseEntry=$this->oc['SourcePot\Datapool\Tools\MiscTools']->arr2flat($sourceEntry);
        $paramsCosts=current($base['processingparamscosts'])['Content'];
        $paramsCases=current($base['processingparamscases'])['Content'];
        // base date check
        $baseDate=$flatCaseEntry[$paramsCases['Base date']];
        // check rule match
        foreach($base['processingrules'] as $ruleIndex=>$rule){
            $rule['Content']['ruleIndex']=$this->oc['SourcePot\Datapool\Foundation\Database']->getOrderedListIndexFromEntryId($ruleIndex);
        }
        // get cost records
        $costRecordsSelector=$base['costsSelector'];
        $case=$sourceEntry[$paramsCases['Case reference']];
        $costRecordsSelector[$paramsCosts['Case reference ']]=$case;
        // add to case store
        $this->casesToProcess[$case]=array('costRecordsSelector'=>$costRecordsSelector,'categoryCases'=>$flatCaseEntry[$paramsCases['Category cases']]);
        /*
        foreach($this->oc['SourcePot\Datapool\Foundation\Database']->entryIterator($costRecordsSelector,TRUE,'Read') as $costEntry){
            $flatCostEntry=$this->oc['SourcePot\Datapool\Tools\MiscTools']->arr2flat($sourceEntry);
        }
        */
        return $result;
    }

    private function dateDiff(string $dateA,string $dateB):array
    {
        $return=array('years'=>0,'months');
        $dateAarr=explode('-',$dateA);
        $dateBarr=explode('-',$dateB);
        $return['years']=intval($dateBarr[0])-intval($dateAarr[0]);
        $return['months']=intval($dateBarr[1])-intval($dateAarr[1]);
        $return['months']=$return['months']+$return['years']*12;
        return $return;
    }

}
?>