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
                return $this->processInvoices($callingElement,$testRunOnly=FALSE);
                }
                break;
            case 'test':
                if (empty($callingElement)){
                    return TRUE;
                } else {
                    return $this->processInvoices($callingElement,$testRunOnly=TRUE);
                }
                break;
            case 'widget':
                if (empty($callingElement)){
                    return TRUE;
                } else {
                    return $this->getInvoicesWidget($callingElement);
                }
                break;
            case 'settings':
                if (empty($callingElement)){
                    return TRUE;
                } else {
                    return $this->getInvoicesSettings($callingElement);
                }
                break;
            case 'info':
                if (empty($callingElement)){
                    return TRUE;
                } else {
                    return $this->getInvoicesInfo($callingElement);
                }
                break;
        }
        return FALSE;
    }

    private function getInvoicesWidget($callingElement){
        $S=$this->oc['SourcePot\Datapool\Tools\MiscTools']->getSeparator();
        $html='';
        // manual check
        $settings=array('orderBy'=>'Name','isAsc'=>TRUE,'limit'=>2,'hideUpload'=>TRUE,'hideApprove'=>FALSE,'hideDecline'=>FALSE,'hideDelete'=>TRUE,'hideRemove'=>TRUE);
        $settings['columns']=array(array('Column'=>'Content'.$S.'UNYCOM'.$S.'Full','Filter'=>''),array('Column'=>'Content'.$S.'Costs','Filter'=>''));
        $wrapperSetting=array();
        $html.=$this->oc['SourcePot\Datapool\Foundation\Container']->container('Invoice manual check','entryList',$callingElement['Content']['Selector'],$settings,$wrapperSetting);
        // invoice widget
        $html.=$this->oc['SourcePot\Datapool\Foundation\Container']->container('Invoices','generic',$callingElement,array('method'=>'getInvoicesWidgetHtml','classWithNamespace'=>__CLASS__),array());
        return $html;
    }

    private function getInvoicesInfo($callingElement){
        $matrix=array();
        $matrix['']['value']='';
        $html=$this->oc['SourcePot\Datapool\Tools\HTMLbuilder']->table(array('matrix'=>$matrix,'hideHeader'=>TRUE,'hideKeys'=>FALSE,'keep-element-content'=>TRUE,'caption'=>'Info'));
        return $html;
    }
       
    public function getInvoicesWidgetHtml($arr){
        if (!isset($arr['html'])){$arr['html']='';}
        // command processing
        $result=array();
        $formData=$this->oc['SourcePot\Datapool\Foundation\Element']->formProcessing(__CLASS__,__FUNCTION__);
        if (isset($formData['cmd']['run'])){
            $result=$this->processInvoices($arr['selector'],FALSE);
        } else if (isset($formData['cmd']['test'])){
            $result=$this->processInvoices($arr['selector'],TRUE);
        }
        // build html
        $btnArr=array('tag'=>'input','type'=>'submit','callingClass'=>__CLASS__,'callingFunction'=>__FUNCTION__);
        $matrix=array();
        $btnArr['value']='Check';
        $btnArr['key']=array('test');
        $matrix['Commands']['Test']=$btnArr;
        $btnArr['value']='Process invoices';
        $btnArr['key']=array('run');
        $matrix['Commands']['Run']=$btnArr;
        $arr['html'].=$this->oc['SourcePot\Datapool\Tools\HTMLbuilder']->table(array('matrix'=>$matrix,'style'=>'clear:left;','hideHeader'=>TRUE,'hideKeys'=>TRUE,'keep-element-content'=>TRUE,'caption'=>'Invoices'));
        foreach($result as $caption=>$matrix){
            $arr['html'].=$this->oc['SourcePot\Datapool\Tools\HTMLbuilder']->table(array('matrix'=>$matrix,'hideHeader'=>FALSE,'hideKeys'=>FALSE,'keep-element-content'=>TRUE,'caption'=>$caption));
        }
        $arr['wrapperSettings']=array('style'=>array('width'=>'fit-content'));
        return $arr;
    }
    
    private function getInvoicesSettings($callingElement){
        $html='';
        if ($this->oc['SourcePot\Datapool\Foundation\Access']->isContentAdmin()){
            $html.=$this->oc['SourcePot\Datapool\Foundation\Container']->container('Invoices entries settings','generic',$callingElement,array('method'=>'getInvoicesSettingsHtml','classWithNamespace'=>__CLASS__),array());
        }
        return $html;
    }
    
    public function getInvoicesSettingsHtml($arr){
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
        $arr['caption']='Invoices control: select forwarding targets and probabilities';
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
        $arr['caption']='Invoices filter rules: defines entry filter for manual checking';
        $html=$this->oc['SourcePot\Datapool\Tools\HTMLbuilder']->entryListEditor($arr);
        return $html;
    }

    private function processInvoices($callingElement,$testRun=FALSE){
        $base=array('processingparams'=>array(),'processingrules'=>array());
        $base=$this->oc['SourcePot\Datapool\Foundation\DataExplorer']->callingElement2settings(__CLASS__,__FUNCTION__,$callingElement,$base);
        // add to base canvas elements->array('EntryId'=>'Name')
        $canvasElements=$this->oc['SourcePot\Datapool\Foundation\DataExplorer']->getCanvasElements($callingElement['Folder']);
        foreach($canvasElements as $index=>$canvasElement){
            $base[$canvasElement['EntryId']]=$canvasElement['Content']['Style']['Text'];
        }
        // loop through source entries and parse these entries
        $this->oc['SourcePot\Datapool\Foundation\Database']->resetStatistic();
        $result=array('Invoices'=>array());
        // loop through entries
        $params=current($base['processingparams']);
        foreach($this->oc['SourcePot\Datapool\Foundation\Database']->entryIterator($callingElement['Content']['Selector'],TRUE,'Read') as $sourceEntry){
            $result['Invoices'][$sourceEntry['Name']]=array('Document missing'=>FALSE,'Property missing'=>FALSE,'Ready for<br/>manual check'=>FALSE,'Rule match'=>FALSE,'User action'=>'none','Forward to'=>'');
            if (empty($sourceEntry['Params']['File'])){
                $result['Invoices'][$sourceEntry['Name']]['Document missing']=$this->oc['SourcePot\Datapool\Tools\MiscTools']->bool2element(TRUE);
                continue;
            } else {
                $result['Invoices'][$sourceEntry['Name']]['Document missing']=$this->oc['SourcePot\Datapool\Tools\MiscTools']->bool2element(FALSE);
            }
            $result=$this->processEntry($base,$sourceEntry,$result,$testRun);
        }
        $result['Statistics']=$this->oc['SourcePot\Datapool\Foundation\Database']->statistic2matrix();
        $result['Statistics']['Script time']=array('Value'=>date('Y-m-d H:i:s'));
        $result['Statistics']['Time consumption [msec]']=array('Value'=>round((hrtime(TRUE)-$base['Script start timestamp'])/1000000));
        return $result;
    }
    
    private function processEntry($base,$sourceEntry,$result,$testRun){
        $params=current($base['processingparams']);
        // check for added manual action
        $targetEntryId=FALSE;
        if (isset($sourceEntry['Params']['User'][$_SESSION['currentUser']['EntryId']]['action'])){
            if ($sourceEntry['Params']['User'][$_SESSION['currentUser']['EntryId']]['action']==='approve'){
                // invoice approved
                $targetEntryId=$params['Content']['Target success'];
            } else if ($sourceEntry['Params']['User'][$_SESSION['currentUser']['EntryId']]['action']==='decline'){
                // declined invoice
                $targetEntryId=$params['Content']['Target failure'];
            } else {
                $this->oc['logger']->log('notice','User action "{action}" did not match "approve" or "decline"',array('action'=>$sourceEntry['Params']['User'][$_SESSION['currentUser']['EntryId']]['action']));
            }
            if ($targetEntryId){
                $result['Invoices'][$sourceEntry['Name']]['User action']='<b>'.$sourceEntry['Params']['User'][$_SESSION['currentUser']['EntryId']]['action'].'</b>';
                $targetEntry=$this->oc['SourcePot\Datapool\Foundation\Database']->moveEntryOverwriteTarget($sourceEntry,$base['entryTemplates'][$targetEntryId],TRUE,$testRun);
                $result['Invoices'][$sourceEntry['Name']]['Forward to']=$base[$targetEntryId];
            }
        }
        // check if rules were already applied
        $rulesWereAppliedAlready=$this->oc['SourcePot\Datapool\Tools\MiscTools']->wasTouchedByClass($sourceEntry,__CLASS__,$testRun);
        $result['Invoices'][$sourceEntry['Name']]['Ready for<br/>manual check']=$this->oc['SourcePot\Datapool\Tools\MiscTools']->bool2element($rulesWereAppliedAlready);
        // check rule match
        $ruleMatch=NULL;
        $flatSourceEntry=$this->oc['SourcePot\Datapool\Tools\MiscTools']->arr2flat($sourceEntry);
        foreach($base['processingrules'] as $ruleIndex=>$rule){
            $rule['Content']['ruleIndex']=$this->oc['SourcePot\Datapool\Foundation\Database']->getOrderedListIndexFromEntryId($ruleIndex);
            if (!isset($flatSourceEntry[$rule['Content']['Property']])){
                $result['Invoices'][$sourceEntry['Name']]['Property missing']=$this->oc['SourcePot\Datapool\Tools\MiscTools']->bool2element(TRUE);
                continue;
            } else {
                $result['Invoices'][$sourceEntry['Name']]['Property missing']=$this->oc['SourcePot\Datapool\Tools\MiscTools']->bool2element(FALSE);
            }
            $property=$this->oc['SourcePot\Datapool\Tools\MiscTools']->convert($flatSourceEntry[$rule['Content']['Property']],$rule['Content']['Property data type']);
            $conditionMet=$this->oc['SourcePot\Datapool\Tools\MiscTools']->isTrue($property,$rule['Content']['Compare value'],$rule['Content']['Condition']);
            if ($ruleMatch===NULL){
                $ruleMatch=$conditionMet;
            } else if ($rule['Content']['...']==='&&'){
                $ruleMatch=$ruleMatch && $conditionMet;
            } else if ($rule['Content']['...']==='||'){
                $ruleMatch=$ruleMatch || $conditionMet;
            } else {
                $this->oc['logger']->log('notice','Rule "{ruleIndex}" is invalid, key "... = {...}" is undefined',$rule['Content']);
            }
        }
        $result['Invoices'][$sourceEntry['Name']]['Rule match']=$this->oc['SourcePot\Datapool\Tools\MiscTools']->bool2element($ruleMatch);
        if ($ruleMatch){
            if (mt_rand(0,99)<$params['Content']['Rules match<br/>sample probability'] || $rulesWereAppliedAlready){
                // manual check
            } else {
                // forward to success target
                $targetEntryId=$params['Content']['Target success'];
                $targetEntry=$this->oc['SourcePot\Datapool\Foundation\Database']->moveEntryOverwriteTarget($sourceEntry,$base['entryTemplates'][$targetEntryId],TRUE,$testRun);
                $result['Invoices'][$sourceEntry['Name']]['Forward to']=$base[$targetEntryId];
            }
        } else {
            if (mt_rand(0,99)<$params['Content']['Rules no match<br/>sample probability'] || $rulesWereAppliedAlready){
                // manual check
            } else {
                // forward to success target
                $targetEntryId=$params['Content']['Target success'];
                $targetEntry=$this->oc['SourcePot\Datapool\Foundation\Database']->moveEntryOverwriteTarget($sourceEntry,$base['entryTemplates'][$targetEntryId],TRUE,$testRun);
                $result['Invoices'][$sourceEntry['Name']]['Forward to']=$base[$targetEntryId];
            }
        }
        return $result;
    }
}
?>