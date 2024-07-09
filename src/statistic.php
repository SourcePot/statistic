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

    private const MAX_PROC_TIME=60000000000;     // max. processing tim in nanoseconds
    private const CACHE_NAME='statisticCache';
    private const STEPS=array(0=>'Added costs to families',1=>'Added meta data to families',2=>'Created statistics');
    private const STEPS_METHOD=array(0=>'addCosts',1=>'addFamilyMeta',2=>'getStatistics');

    private $skipCasesOptions=array('skipUngranted'=>'Skip if not granted');
    
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
        } else if (isset($formData['cmd']['reset'])){
            $statisticFamilySelector=$this->oc['SourcePot\Datapool\Foundation\DataExplorer']->dataTmpSelector(__CLASS__,self::CACHE_NAME,FALSE);
            $this->oc['SourcePot\Datapool\Foundation\Database']->resetStatistic();
            $this->oc['SourcePot\Datapool\Foundation\Database']->deleteEntries($statisticFamilySelector,TRUE);
            $result['Statistics']=$this->oc['SourcePot\Datapool\Foundation\Database']->statistic2matrix();
        }
        // build html
        $familySelector=$this->oc['SourcePot\Datapool\Foundation\DataExplorer']->dataTmpSelector(__CLASS__,self::CACHE_NAME,FALSE);
        $familyRowCount=$this->oc['SourcePot\Datapool\Foundation\Database']->getRowCount($familySelector,TRUE);
        $btnArr=array('tag'=>'input','type'=>'submit','callingClass'=>__CLASS__,'callingFunction'=>__FUNCTION__);
        $matrix=array();
        $btnArr['value']='Run';
        $btnArr['key']=array('run');
        $matrix['Commands']['Run']=$btnArr;
        $btnArr['value']='Reset';
        $btnArr['hasCover']=TRUE;
        $btnArr['key']=array('reset');
        $matrix['Commands']['Reset']=$btnArr;
        $matrix['Commands']['Info']='Case cache = '.$familyRowCount;
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
        $arr['html'].=$this->processingParamsCosts($arr['selector']);
        $arr['html'].=$this->processingParamsCases($arr['selector']);
        $arr['html'].=$this->processingParamsFamilies($arr['selector']);
        return $arr;
    }

    private function processingParamsCosts($callingElement):string
    {
        $contentStructure=array('Cost records'=>array('method'=>'canvasElementSelect','excontainer'=>FALSE),
                                'Case reference'=>array('method'=>'keySelect','excontainer'=>TRUE,'value'=>'Folder','standardColumsOnly'=>TRUE,'showSample'=>TRUE,'addSourceValueColumn'=>FALSE,'keep-element-content'=>FALSE),
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
            $contentStructure['Case reference']+=$base['entryTemplates'][$params['Content']['Cost records']];
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
    
    private function processingParamsCases($callingElement):string
    {
        $contentStructure=array('Case records'=>array('method'=>'canvasElementSelect','excontainer'=>FALSE),
                                'Case reference '=>array('method'=>'keySelect','value'=>'Name','standardColumsOnly'=>TRUE,'showSample'=>TRUE,'addSourceValueColumn'=>FALSE,'excontainer'=>TRUE,'keep-element-content'=>FALSE),
                                'Prio date'=>array('method'=>'keySelect','excontainer'=>TRUE,'value'=>'Content|[]|Prioritätsdatum|[]|System short','showSample'=>TRUE,'addSourceValueColumn'=>FALSE,'keep-element-content'=>FALSE),
                                'Base date'=>array('method'=>'keySelect','excontainer'=>TRUE,'value'=>'Content|[]|Zuerkannter Anmeldetag|[]|System short','showSample'=>TRUE,'addSourceValueColumn'=>FALSE,'keep-element-content'=>FALSE),
                                'Grant date'=>array('method'=>'keySelect','excontainer'=>TRUE,'value'=>'Content|[]|Erteilungsdatum|[]|System short','showSample'=>TRUE,'addSourceValueColumn'=>FALSE,'keep-element-content'=>FALSE),
                                'Category cases'=>array('method'=>'keySelect','excontainer'=>TRUE,'value'=>'Content|[]|Codepfad all|[]|FhI','showSample'=>TRUE,'addSourceValueColumn'=>FALSE,'keep-element-content'=>FALSE),
                                'Bins'=>array('method'=>'select','excontainer'=>TRUE,'value'=>'yearly','options'=>array('years'=>'Years','months'=>'Months'),'keep-element-content'=>FALSE),
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
            $contentStructure['Case reference ']+=$base['entryTemplates'][$params['Content']['Case records']];
            $contentStructure['Prio date']+=$base['entryTemplates'][$params['Content']['Case records']];
            $contentStructure['Base date']+=$base['entryTemplates'][$params['Content']['Case records']];
            $contentStructure['Grant date']+=$base['entryTemplates'][$params['Content']['Case records']];
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
    
    private function processingParamsFamilies($callingElement):string
    {
        $contentStructure=array('If not granted'=>array('method'=>'select','excontainer'=>TRUE,'value'=>'skip','options'=>array('skip'=>'Skip','include'=>'Include')),
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
        // get HTML
        $arr['canvasCallingClass']=$callingElement['Folder'];
        $arr['contentStructure']=$contentStructure;
        $arr['caption']='Family control';
        $arr['noBtns']=TRUE;
        $row=$this->oc['SourcePot\Datapool\Tools\HTMLbuilder']->entry2row($arr,FALSE,TRUE);
        if (empty($arr['selector']['Content'])){$row['trStyle']=array('background-color'=>'#a00');}
        $matrix=array('Parameter'=>$row);
        return $this->oc['SourcePot\Datapool\Tools\HTMLbuilder']->table(array('matrix'=>$matrix,'style'=>'clear:left;','hideHeader'=>FALSE,'hideKeys'=>TRUE,'keep-element-content'=>TRUE,'caption'=>$arr['caption']));
    }

    private function checkCache():array
    {
        // init meta cache
        $cacheMeta=array('rowCount'=>0,'cacheEmpty'=>TRUE);
        $cacheMeta['selector']=$this->oc['SourcePot\Datapool\Foundation\DataExplorer']->dataTmpSelector(__CLASS__,self::CACHE_NAME,FALSE);
        foreach(self::STEPS as $stepIndex=>$step){
            $cacheMeta['stepDone'][$stepIndex]=TRUE;
            $cacheMeta['unprocessedCount'][$stepIndex]=0;
            $cacheMeta['processedCount'][$stepIndex]=0;
        }
        // gather meta data
        foreach($this->oc['SourcePot\Datapool\Foundation\Database']->entryIterator($cacheMeta['selector'],TRUE,'Read') as $cacheEntry){
            $cacheMeta['rowCount']++;
            $cacheMeta['cacheEmpty']=FALSE;
            foreach(self::STEPS as $stepIndex=>$step){
                if (empty($cacheEntry['Content']['processedStep'][$stepIndex])){
                    $cacheMeta['stepDone'][$stepIndex]=FALSE;
                    $cacheMeta['unprocessedCount'][$stepIndex]++;
                } else {
                    $cacheMeta['processedCount'][$stepIndex]++;
                }
            }
        }
        return $cacheMeta;
    }

    private function processStatistic($callingElement,$testRun=FALSE):array
    {
        $base=array('processingparamscosts'=>array(),'processingparamscases'=>array(),'processingparamsfamilies'=>array());
        $base=$this->oc['SourcePot\Datapool\Foundation\DataExplorer']->callingElement2settings(__CLASS__,__FUNCTION__,$callingElement,$base);
        $base['targetEntry']=$callingElement['Content']['Selector'];
        $paramsCosts=current($base['processingparamscosts'])['Content'];
        $paramsCases=current($base['processingparamscases'])['Content'];
        $base['costsSelector']=$base['entryTemplates'][$paramsCosts['Cost records']];
        $base['casesSelector']=$base['entryTemplates'][$paramsCases['Case records']];
        $base['canvasElement']=$callingElement['Content'];
        //
        $this->oc['SourcePot\Datapool\Foundation\Database']->resetStatistic();
        $result=array('Statistic'=>array('Step'=>array('value'=>''),
                                        'Entries'=>array('value'=>0),
                                        'Entries processed'=>array('value'=>0),
                                        'Entries skipped'=>array('value'=>0),
                                        'Errors'=>array('value'=>''),
                                        )
                     );        
        $cacheMeta=$this->checkCache();
        //$this->oc['SourcePot\Datapool\Tools\MiscTools']->arr2file($cacheMeta);
        if ($cacheMeta['cacheEmpty']){
            // create cache -> gather families from entries
            $family=$this->finalizeFamily($base);
            $result['Statistic']['Step']['value']='0: Create families from patent cases';
            foreach($this->oc['SourcePot\Datapool\Foundation\Database']->entryIterator($base['casesSelector'],TRUE,'Read',$paramsCases['Case reference '],TRUE) as $caseEntry){
                // check if entry is valid
                $flatCaseEntry=$this->oc['SourcePot\Datapool\Tools\MiscTools']->arr2flat($caseEntry);
                $unycomArr=$this->oc['SourcePot\Datapool\Tools\MiscTools']->convert2unycom($flatCaseEntry[$paramsCases['Case reference ']]);
                if (!$unycomArr['isValid']){
                    $result['Statistic']['Errors']=$this->oc['SourcePot\Datapool\Tools\MiscTools']->addArrValuesKeywise($result['Statistic']['Errors'],array('value'=>'Invalid case ref '.$flatCaseEntry[$paramsCases['Case reference ']]));
                    continue;
                }
                // check if entry belongs to new family
                $familyName=$unycomArr['Type'].$unycomArr['Number'];
                if (strcmp($family['Family'],$familyName)!==0){
                    $family=$this->finalizeFamily($base,$family);
                }
                // add case to family
                $family=$this->addCase2family($base,$flatCaseEntry,$unycomArr,$family);
                $result['Statistic']['Entries']['value']++;
            }
            $family=$this->finalizeFamily($base,$family);
            $result['Statistic']['Families']['value']=$family['familyCount'];
        } else {
            // process cache entries step-by-step
            foreach(self::STEPS as $stepIndex=>$step){
                if ($cacheMeta['stepDone'][$stepIndex]){continue;}
                $result['Statistic']['Step']['value']=($stepIndex+1).': '.$step;
                $method=self::STEPS_METHOD[$stepIndex];
                foreach($this->oc['SourcePot\Datapool\Foundation\Database']->entryIterator($cacheMeta['selector'],TRUE,'Read','Name',TRUE) as $cacheEntry){
                    // process entry
                    $cacheEntry['stepIndex']=$stepIndex;
                    if (empty($cacheEntry['Content']['processedStep'][$stepIndex])){
                        // needs to be processed
                        $result=$this->$method($base,$cacheEntry,$result,$testRun);
                        $result['Statistic']['Entries processed']['value']++;
                    } else {
                        // was processed already
                        $result['Statistic']['Entries skipped']['value']++;
                    }
                    $result['Statistic']['Entries']['value']++;
                    // check processing time
                    if (hrtime(TRUE)-$base['Script start timestamp']>self::MAX_PROC_TIME){
                        $count=$cacheMeta['unprocessedCount'][$stepIndex]+$cacheMeta['processedCount'][$stepIndex];
                        $result['Statistic']['Step']['value'].=' <b>('.$cacheMeta['processedCount'][$stepIndex].'+'.$result['Statistic']['Entries processed']['value'].' of '.$count.')</b>';
                        break;
                    }
                } // end of loop through families
                break;
            }   // end of loop through steps
        }
        $result['Statistics']=$this->oc['SourcePot\Datapool\Foundation\Database']->statistic2matrix();
        $result['Statistics']['Script time']=array('Value'=>date('Y-m-d H:i:s'));
        $result['Statistics']['Time consumption [msec]']=array('Value'=>round((hrtime(TRUE)-$base['Script start timestamp'])/1000000));
        return $result;
    }
    
    private function addCase2family($base,$flatCaseEntry,$unycomArr,$family):array
    {
        $paramsCosts=current($base['processingparamscosts'])['Content'];
        $paramsCases=current($base['processingparamscases'])['Content'];
        // add family meta data
        if (empty($family['Family'])){
            $family['Family']=$unycomArr['Type'].$unycomArr['Number'];
            $family['Info']['costSelector']=$base['costsSelector'];
            $family['Info']['costSelector'][$paramsCosts['Case reference']]='%'.$family['Family'].'%';    
            $family['Info']['prioDate']=$flatCaseEntry[$paramsCases['Prio date']];
            $family['Info']['categoryCases']=$flatCaseEntry[$paramsCases['Category cases']];
            $family['Info']['categoryCosts']=array();
            $family['Info']['bins']=$paramsCases['Bins'];
            $family['Info']['isGranted']=FALSE;
            $family['Info']['sum']=0;
        }
        // add to case store
        $baseDate=$flatCaseEntry[$paramsCases['Base date']];
        $grantDate=$flatCaseEntry[$paramsCases['Grant date']];
        $grantedArr=empty($grantDate)?array('years'=>21,'months'=>240):$this->dateDiff($baseDate,$grantDate);
        $isFirstApplication=($family['Info']['prioDate']==$baseDate);
        $ageArr=$this->dateDiff($baseDate,date('Y-m-d'));
        $family['Info']['datePCT']=($unycomArr['Region']=='WO')?$baseDate:'';
        if ($ageArr['years']>=0){
            $case=$unycomArr['Reference'];
            if ($grantedArr['years']<=20){$family['Info']['isGranted']=TRUE;}
            $family[$case]=array('baseDate'=>$baseDate,'grantDate'=>$grantDate,'isFirstApplication'=>$isFirstApplication,'age years'=>$ageArr['years'],'year granted'=>$grantedArr['years'],'categoryCases'=>$flatCaseEntry[$paramsCases['Category cases']]);
            $family[$case]['isPCT']=($unycomArr['Region']=='WO');
            $family[$case]['isValidation']=(($unycomArr['Region']==='EP' || $unycomArr['Region']==='WE') && $unycomArr['Country']!=='  ');
            $family[$case]['sum']=0;
            $family[$case]['isDivisional']=0;
            // init bins
            if ($family['Info']['bins']=='years'){$limit=20;} else {$limit=240;}
            for($bin=0;$bin<=$limit;$bin++){$family[$case]['bins'][$bin]=0;}
        }
        return $family;
    }

    private function finalizeFamily(array $base,array $family=array('familyCount'=>1,'Family'=>'')):array
    {
        $paramsFamilies=current($base['processingparamsfamilies'])['Content'];
        //$this->oc['SourcePot\Datapool\Tools\MiscTools']->arr2file($paramsFamilies);
        $skipFamily=($paramsFamilies['If not granted']=='skip' && empty($family['Info']['isGranted']));
        if (empty($family['Family']) || $skipFamily){
            // skip family
        } else {
            // store family
            $entry=$this->oc['SourcePot\Datapool\Foundation\DataExplorer']->dataTmpSelector(__CLASS__,self::CACHE_NAME,$family['Family']);
            $entry['Content']=$family;
            $entry['Content']['Source']=$entry['Source'];
            $entry['Content']['EntryId']=$entry['EntryId'];
            $this->oc['SourcePot\Datapool\Foundation\Database']->updateEntry($entry,TRUE);
            $family['familyCount']++;
        }
        $family=array('familyCount'=>$family['familyCount'],'Family'=>'');
        return $family;
    }

    private function addCosts($base,$cacheEntry,$result,$testRun):array
    {
        $paramsCosts=current($base['processingparamscosts'])['Content'];
        $paramsCases=current($base['processingparamscases'])['Content'];
        // add costs
        foreach($this->oc['SourcePot\Datapool\Foundation\Database']->entryIterator($cacheEntry['Content']['Info']['costSelector'],TRUE,'Read') as $costEntry){
            $flatCostEntry=$this->oc['SourcePot\Datapool\Tools\MiscTools']->arr2flat($costEntry);
            $unycomArr=$this->oc['SourcePot\Datapool\Tools\MiscTools']->convert2unycom($flatCostEntry[$paramsCosts['Case reference']]);
            if (!$unycomArr['isValid']){
                $result['Statistic']['Errors']=$this->oc['SourcePot\Datapool\Tools\MiscTools']->addArrValuesKeywise($result['Statistic']['Errors'],array('value'=>'Invalid case ref '.$flatCostEntry[$paramsCosts['Case reference']]));
                continue;
            }
            $case=$unycomArr['Reference'];
            $cacheEntry['Content']['Info']['sum']+=$flatCostEntry[$paramsCosts['Cost record amount']];
            if (isset($cacheEntry['Content'][$case])){
                // cost record matches family case
                if (isset($result['Statistic']['Cost record added']['value'])){$result['Statistic']['Cost record added']['value']++;} else {$result['Statistic']['Cost record added']['value']=1;}
                if ($cacheEntry['Content'][$case]['isValidation']){
                    $timeDiffArr=$this->dateDiff($cacheEntry['Content'][$case]['grantDate'],$flatCostEntry[$paramsCosts['Cost record date']]);
                } else {
                    $timeDiffArr=$this->dateDiff($cacheEntry['Content'][$case]['baseDate'],$flatCostEntry[$paramsCosts['Cost record date']]);
                }
                // family costs
                if (isset($cacheEntry['Content']['Info']['categoryCosts'][$flatCostEntry[$paramsCosts['Category costs']]])){
                    $cacheEntry['Content']['Info']['categoryCosts'][$flatCostEntry[$paramsCosts['Category costs']]]+=$flatCostEntry[$paramsCosts['Cost record amount']];
                } else {
                    $cacheEntry['Content']['Info']['categoryCosts'][$flatCostEntry[$paramsCosts['Category costs']]]=$flatCostEntry[$paramsCosts['Cost record amount']];
                }
                $bin=($timeDiffArr[$cacheEntry['Content']['Info']['bins']]<0)?0:$timeDiffArr[$cacheEntry['Content']['Info']['bins']];
                if (isset($cacheEntry['Content'][$case]['bins'][$bin])){
                    // case costs
                    $cacheEntry['Content'][$case]['bins'][$bin]+=$flatCostEntry[$paramsCosts['Cost record amount']];
                    $cacheEntry['Content'][$case]['sum']+=$flatCostEntry[$paramsCosts['Cost record amount']];
                    if (isset($cacheEntry['Content'][$case]['categoryCosts'][$flatCostEntry[$paramsCosts['Category costs']]])){
                        $cacheEntry['Content'][$case]['categoryCosts'][$flatCostEntry[$paramsCosts['Category costs']]]+=$flatCostEntry[$paramsCosts['Cost record amount']];
                    } else {
                        $cacheEntry['Content'][$case]['categoryCosts'][$flatCostEntry[$paramsCosts['Category costs']]]=$flatCostEntry[$paramsCosts['Cost record amount']];
                    }
                } else {
                    $result['Statistic']['Errors']=$this->oc['SourcePot\Datapool\Tools\MiscTools']->addArrValuesKeywise($result['Statistic']['Errors'],array('value'=>'Invalid bin'.$bin.' '.$case));
                }
            } else {
                // cost record does not match any family case
                if (isset($result['Statistic']['Cost record skipped']['value'])){
                    $result['Statistic']['Cost record skipped']['value']++;
                } else {
                    $result['Statistic']['Cost record skipped']['value']=1;
                }
                $result['Statistic']['Errors']=$this->oc['SourcePot\Datapool\Tools\MiscTools']->addArrValuesKeywise($result['Statistic']['Errors'],array('value'=>'Invalid case cost record '.$case));
            }
            $result['Statistic']['Family']['value']=$cacheEntry['Content']['Family'];
        }
        // update cache entry
        $cacheEntry['Content']['processedStep'][$cacheEntry['stepIndex']]=TRUE;
        $this->oc['SourcePot\Datapool\Foundation\Database']->updateEntry($cacheEntry,TRUE);
        return $result;
    }

    private function addFamilyMeta($base,$cacheEntry,$result,$testRun):array
    {
        $paramsCosts=current($base['processingparamscosts'])['Content'];
        $paramsCases=current($base['processingparamscases'])['Content'];
        // add family case meta
        $family=$cacheEntry['Content'];
        foreach($family as $case=>$caseArr){
            if ($case=='Info' || $case=='familyCount' || $case=='Family' || $case=='Family' || $case=='Source' || $case=='EntryId' || $case=='processedStep'){continue;}
            $unycomArr=$this->oc['SourcePot\Datapool\Tools\MiscTools']->convert2unycom($case);
            $cacheEntry['Content'][$case]['isDivisional']=FALSE;
            $cacheEntry['Content'][$case]['isPCTphase']=($caseArr['baseDate']==$family['Info']['datePCT']);
            $sum=0;
            $cacheEntry['Content'][$case]['sumTillGrant']=0;
            foreach($family[$case]['bins'] as $year=>$yearSum){
                $sum+=$yearSum;
                if ($year<=$caseArr['year granted'] && $caseArr['year granted']<21){
                    $cacheEntry['Content'][$case]['sumTillGrant']+=$yearSum;
                }
                if ($cacheEntry['Content'][$case]['isPCTphase'] && !$cacheEntry['Content'][$case]['isValidation'] && $year>3 && $sum===0){
                    $cacheEntry['Content'][$case]['isDivisional']=TRUE;
                } else if (!$cacheEntry['Content'][$case]['isPCTphase'] && !$cacheEntry['Content'][$case]['isValidation'] && $year>1 && $sum===0){
                    $cacheEntry['Content'][$case]['isDivisional']=TRUE;
                }
            }
            ksort($cacheEntry['Content']['Info']['categoryCosts']);
            $cacheEntry['Content'][$case]['attorney']=implode(' | ',array_keys($cacheEntry['Content']['Info']['categoryCosts']));
            $cacheEntry['Content'][$case]['type']=$unycomArr['Country'].$unycomArr['Region'].(($cacheEntry['Content'][$case]['isDivisional'])?'dv':'--');
            $cacheEntry['Content'][$case]['name']='';
            $cacheEntry['Content'][$case]['name'].='_'.$cacheEntry['Content'][$case]['type'];
            $cacheEntry['Content'][$case]['name'].='_'.$family['Info']['categoryCases'];
            $cacheEntry['Content'][$case]['name'].='_'.$this->oc['SourcePot\Datapool\Tools\MiscTools']->getHash($cacheEntry['Content'][$case]['attorney'],TRUE);
            $cacheEntry['Content'][$case]['name']=trim(str_replace(' ','-',$cacheEntry['Content'][$case]['name']),'_');
        }
        // update cache entry
        $cacheEntry['Content']['processedStep'][$cacheEntry['stepIndex']]=TRUE;
        $this->oc['SourcePot\Datapool\Foundation\Database']->updateEntry($cacheEntry,TRUE);
        return $result;
    }

    private function getStatistics($base,$cacheEntry,$result,$testRun):array
    {
        $paramsCosts=current($base['processingparamscosts'])['Content'];
        $paramsCases=current($base['processingparamscases'])['Content'];
        // add cases to statistics session 
        foreach($cacheEntry['Content'] as $case=>$caseArr){
            if ($case=='Info' || $case=='familyCount' || $case=='Family' || $case=='Family' || $case=='Source' || $case=='EntryId' || $case=='processedStep'){continue;}
            // get grant statistic
            $name=$caseArr['name'];
            $age=$caseArr['age years'];
            if ($caseArr['sumTillGrant']>0){
                if (isset($_SESSION[__CLASS__]['grants'][$name][$age])){
                    $_SESSION[__CLASS__]['grants'][$name][$age]['Samples']++;
                    $_SESSION[__CLASS__]['grants'][$name][$age]['Sum costs']+=$caseArr['sumTillGrant'];
                    if ($_SESSION[__CLASS__]['grants'][$name][$age]['Min costs']>$caseArr['sumTillGrant']){$_SESSION[__CLASS__]['grants'][$name][$age]['Min costs']=$caseArr['sumTillGrant'];}
                    if ($_SESSION[__CLASS__]['grants'][$name][$age]['Max costs']<$caseArr['sumTillGrant']){$_SESSION[__CLASS__]['grants'][$name][$age]['Max costs']=$caseArr['sumTillGrant'];}
                } else {
                    $_SESSION[__CLASS__]['grants'][$name][$age]=array('Age'=>$age,'Avg. costs'=>FALSE,'Min costs'=>$caseArr['sumTillGrant'],'Max costs'=>$caseArr['sumTillGrant'],'Sum costs'=>$caseArr['sumTillGrant'],'Samples'=>1,'Type'=>$caseArr['type'],'Attorney'=>$caseArr['attorney']);
                }
            }
            // get yearly statistic
            $name=$caseArr['age years'].'_'.$caseArr['name'];
            foreach($caseArr['bins'] as $year=>$yearSum){
                if (!isset($_SESSION[__CLASS__]['years'][$name][$year])){
                    $_SESSION[__CLASS__]['years'][$name][$year]=array('Avg. costs'=>FALSE,'Min costs'=>FALSE,'Max costs'=>FALSE,'Sum costs'=>0,'Samples'=>0,'Granted'=>0,'Cases'=>0,'Type'=>$caseArr['type'],'Age'=>$caseArr['age years'],'Attorney'=>$caseArr['attorney']);
                }
                $_SESSION[__CLASS__]['years'][$name][$year]['Cases']++;
                if ($year<=$caseArr['year granted']){
                    if ($_SESSION[__CLASS__]['years'][$name][$year]['Min costs']===FALSE || $_SESSION[__CLASS__]['years'][$name][$year]['Min costs']>$yearSum){$_SESSION[__CLASS__]['years'][$name][$year]['Min costs']=$yearSum;}
                    if ($_SESSION[__CLASS__]['years'][$name][$year]['Max costs']===FALSE || $_SESSION[__CLASS__]['years'][$name][$year]['Max costs']<$yearSum){$_SESSION[__CLASS__]['years'][$name][$year]['Max costs']=$yearSum;}
                    $_SESSION[__CLASS__]['years'][$name][$year]['Sum costs']+=$yearSum;
                    $_SESSION[__CLASS__]['years'][$name][$year]['Samples']++;
                }
                if ($year>=$caseArr['year granted'] || $caseArr['isValidation']){
                    $_SESSION[__CLASS__]['years'][$name][$year]['Granted']++;
                }
            }
        }
        // finalize statistics
        if ($cacheEntry['isLast']){
            $entry=$ageEntry=$base['targetEntry'];
            $addFolder=empty($entry['Folder']);
            $entry['Read']=$ageEntry['Read']=$this->entryTemplate['Read']['value'];
            $entry['Write']=$ageEntry['Read']=$this->entryTemplate['Write']['value'];
            // grant statistic to csv
            foreach($_SESSION[__CLASS__]['grants'] as $name=>$ages){
                $ageEntry['Content']=array();
                $ageEntry['Name']=$name;
                $ageEntry=$this->oc['SourcePot\Datapool\Tools\MiscTools']->addEntryId($ageEntry,array('Name'),'0','',FALSE);
                foreach($ages as $age=>$ageArr){
                    if (empty($ageArr['Samples'])){continue;}
                    if ($addFolder){                        
                        $ageEntry['Folder']=$ageArr['Attorney'];
                    }
                    $ageEntry['Content']=array('Age'=>$ageArr['Age'],'Avg. costs'=>round($ageArr['Sum costs']/$ageArr['Samples']),'Min costs'=>round($ageArr['Min costs']),'Max costs'=>round($ageArr['Max costs']),'Samples'=>$ageArr['Samples'],'Type'=>$ageArr['Type'],'Attorney'=>$ageArr['Attorney']);
                    $this->oc['SourcePot\Datapool\Tools\CSVtools']->entry2csv($ageEntry);
                }
            }
            unset($_SESSION[__CLASS__]['grants']);
            // yearly statistic to csv
            foreach($_SESSION[__CLASS__]['years'] as $name=>$years){
                $entry['Content']=array();
                $entry['Name']=$name;
                $entry=$this->oc['SourcePot\Datapool\Tools\MiscTools']->addEntryId($entry,array('Name'),'0','',FALSE);
                foreach($years as $year=>$yearArr){
                    if (empty($yearArr['Samples']) || empty($yearArr['Cases'])){continue;}
                    if ($addFolder){                        
                        $entry['Folder']=$yearArr['Attorney'];
                    }
                    $entry['Content']=array('Year'=>$year,'Avg. costs'=>round($yearArr['Sum costs']/$yearArr['Samples']),'Min costs'=>round($yearArr['Min costs']),'Max costs'=>round($yearArr['Max costs']),'Samples'=>$yearArr['Samples'],'Granted [%]'=>round(100*$yearArr['Granted']/$yearArr['Cases']),'Type'=>$yearArr['Type'],'Age'=>$yearArr['Age'],'Attorney'=>$yearArr['Attorney']);
                    $this->oc['SourcePot\Datapool\Tools\CSVtools']->entry2csv($entry);
                }
            }
            unset($_SESSION[__CLASS__]['years']);
        }
        // delete cache entry
        //$this->oc['SourcePot\Datapool\Foundation\Database']->deleteEntries($cacheEntry,TRUE);
        return $result;
    }

    /**
    * The method returns an array containing the date differebce in years and in months.
    *
    * @param string $dateA is the older date, format YYYY-MM-DD  
    * @param string $dateB is the younger date, format YYYY-MM-DD
    * @return array Contains key 'years' representing the amount of full years, and key 'months' representing the amount of full months
    */
    private function dateDiff(string $dateA,string $dateB):array
    {
        $return=array('years'=>0,'months'=>0);
        $dateAarr=explode('-',$dateA);
        $dateBarr=explode('-',$dateB);
        $return['years']=intval($dateBarr[0])-intval($dateAarr[0]);
        $return['months']=intval($dateBarr[1])-intval($dateAarr[1]);
        if ($return['months']<0){
            $return['months']=12+$return['months'];
            $return['years']-=1;
        }
        $return['months']=$return['months']+$return['years']*12;
        return $return;
    }

}
?>