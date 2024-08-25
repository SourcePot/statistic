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

    private const MAX_PROC_TIME=10000000000;     // max. processing tim in nanoseconds
    private const STEPS=array(0=>'Added costs to families',1=>'Added meta data to families',2=>'Created statistics');
    private const STEPS_METHOD=array(0=>'addCosts',1=>'addFamilyMeta',2=>'createStatistics');

    private $skipCasesOptions=array('skipUngranted'=>'Skip if not granted');
    
    public function __construct($oc){
        $this->oc=$oc;
        $table=str_replace(__NAMESPACE__,'',__CLASS__);
        $this->entryTable=mb_strtolower(trim($table,'\\'));
    }
    
    public function init()
    {
        $this->entryTemplate=$this->oc['SourcePot\Datapool\Foundation\Database']->getEntryTemplateCreateTable($this->entryTable,__CLASS__);
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
        return '';
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
            $this->oc['SourcePot\Datapool\Foundation\Database']->resetStatistic();
            $this->oc['SourcePot\Datapool\Foundation\Queue']->clearQueue(__CLASS__);
            $result['Statistics']=$this->oc['SourcePot\Datapool\Foundation\Database']->statistic2matrix();
        }
        // build html
        $btnArr=array('tag'=>'input','type'=>'submit','callingClass'=>__CLASS__,'callingFunction'=>__FUNCTION__);
        $matrix=array();
        $btnArr['value']='Run';
        $btnArr['key']=array('run');
        $matrix['Commands']['Run']=$btnArr;
        $btnArr['value']='Reset';
        $btnArr['hasCover']=TRUE;
        $btnArr['key']=array('reset');
        $matrix['Commands']['Reset']=$btnArr;
        $arr['html'].=$this->oc['SourcePot\Datapool\Tools\HTMLbuilder']->table(array('matrix'=>$matrix,'style'=>'clear:left;','hideHeader'=>TRUE,'hideKeys'=>TRUE,'keep-element-content'=>TRUE,'caption'=>'Statistic'));
        foreach($result as $caption=>$matrix){
            $arr['html'].=$this->oc['SourcePot\Datapool\Tools\HTMLbuilder']->table(array('matrix'=>$matrix,'hideHeader'=>FALSE,'hideKeys'=>FALSE,'keep-element-content'=>TRUE,'caption'=>$caption));
        }
        $cacheMeta=$this->oc['SourcePot\Datapool\Foundation\Queue']->getQueueMeta(__CLASS__,self::STEPS);
        $arr['html'].=$cacheMeta['Meter'];
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
        $arr['html'].=$this->processingParamsGeneric($arr['selector']);
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
        $contentStructure=array('Case reference '=>array('method'=>'keySelect','value'=>'Name','standardColumsOnly'=>TRUE,'showSample'=>TRUE,'addSourceValueColumn'=>FALSE,'excontainer'=>TRUE,'keep-element-content'=>FALSE),
                                'Prio date'=>array('method'=>'keySelect','excontainer'=>TRUE,'value'=>'Content|[]|Prioritätsdatum|[]|System short','showSample'=>TRUE,'addSourceValueColumn'=>FALSE,'keep-element-content'=>FALSE),
                                'Base date'=>array('method'=>'keySelect','excontainer'=>TRUE,'value'=>'Content|[]|Zuerkannter Anmeldetag|[]|System short','showSample'=>TRUE,'addSourceValueColumn'=>FALSE,'keep-element-content'=>FALSE),
                                'Grant date'=>array('method'=>'keySelect','excontainer'=>TRUE,'value'=>'Content|[]|Erteilungsdatum|[]|System short','showSample'=>TRUE,'addSourceValueColumn'=>FALSE,'keep-element-content'=>FALSE),
                                'Category cases'=>array('method'=>'keySelect','excontainer'=>TRUE,'value'=>'Content|[]|Codepfad all|[]|FhI','showSample'=>TRUE,'addSourceValueColumn'=>FALSE,'keep-element-content'=>FALSE),
                                'Bins'=>array('method'=>'select','excontainer'=>TRUE,'value'=>'years','options'=>array('years'=>'Years','months'=>'Months'),'keep-element-content'=>FALSE),
                                'Target'=>array('method'=>'canvasElementSelect','excontainer'=>FALSE),
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
        $contentStructure['Case reference ']+=$callingElement['Content']['Selector'];
        $contentStructure['Prio date']+=$callingElement['Content']['Selector'];
        $contentStructure['Base date']+=$callingElement['Content']['Selector'];
        $contentStructure['Grant date']+=$callingElement['Content']['Selector'];
        $contentStructure['Category cases']+=$callingElement['Content']['Selector'];
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
    
    private function processingParamsGeneric($callingElement):string
    {
        $contentStructure=array('If not granted'=>array('method'=>'select','excontainer'=>TRUE,'value'=>'skip','options'=>array('skip'=>'Skip','include'=>'Include'),'excontainer'=>FALSE),
                                '||'=>array('method'=>'element','tag'=>'p','element-content'=>'||','excontainer'=>FALSE),
                                'Category cases needle'=>array('method'=>'element','tag'=>'input','type'=>'text','value'=>'2440','excontainer'=>FALSE),
                                'Cases needle alias'=>array('method'=>'element','tag'=>'input','type'=>'text','value'=>'IIS1','excontainer'=>FALSE),
                                '|'=>array('method'=>'element','tag'=>'p','element-content'=>'|','excontainer'=>FALSE),
                                'Category costs needle'=>array('method'=>'element','tag'=>'input','type'=>'text','value'=>'choppe','excontainer'=>FALSE),
                                'Costs needle alias'=>array('method'=>'element','tag'=>'input','type'=>'text','value'=>'Schoppe','excontainer'=>FALSE),
                                );
        // get selector
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
        $arr['caption']='Generic control';
        $arr['noBtns']=TRUE;
        $row=$this->oc['SourcePot\Datapool\Tools\HTMLbuilder']->entry2row($arr,FALSE,TRUE);
        if (empty($arr['selector']['Content'])){$row['trStyle']=array('background-color'=>'#a00');}
        $matrix=array('Parameter'=>$row);
        return $this->oc['SourcePot\Datapool\Tools\HTMLbuilder']->table(array('matrix'=>$matrix,'style'=>'clear:left;','hideHeader'=>FALSE,'hideKeys'=>TRUE,'keep-element-content'=>TRUE,'caption'=>$arr['caption']));
    }
    
    private function processStatistic($callingElement,$testRun=FALSE):array
    {
        $base=array('processingparamscosts'=>array(),'processingparamscases'=>array(),'processingparamsgeneric'=>array());
        $base=$this->oc['SourcePot\Datapool\Foundation\DataExplorer']->callingElement2settings(__CLASS__,__FUNCTION__,$callingElement,$base);
        $paramsCosts=current($base['processingparamscosts'])['Content'];
        $paramsCases=current($base['processingparamscases'])['Content'];
        $base['costsSelector']=$base['entryTemplates'][$paramsCosts['Cost records']];
        $base['targetSelector']=$base['entryTemplates'][$paramsCases['Target']];
        $base['canvasElementEntryId']=$callingElement['EntryId'];
        //
        $result=array('Statistic'=>array('Step'=>array('value'=>''),
                                        'Entries'=>array('value'=>0),
                                        'Errors'=>array('value'=>''),
                                        )
                     );
        $cacheMeta=$this->oc['SourcePot\Datapool\Foundation\Queue']->getQueueMeta(__CLASS__,self::STEPS);
        $base['stepIndex']=$cacheMeta['Current step'];
        if ($cacheMeta['Empty']){
            // create cache -> gather families from entries
            $family=$this->finalizeFamily($base);
            $result['Statistic']['Step']['value']='0: Create families from patent cases';
            foreach($this->oc['SourcePot\Datapool\Foundation\Database']->entryIterator($base['callingElement']['Selector'],TRUE,'Read',$paramsCases['Case reference '],TRUE) as $caseEntry){
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
            $result['Statistics']=$this->oc['SourcePot\Datapool\Foundation\Database']->statistic2matrix();
        } else if ($cacheMeta['All done']){
            $result['Statistic']['Step']['value']='Finalizing';
            $result=$this->finalizeStatistics($base);
            $this->oc['SourcePot\Datapool\Foundation\Queue']->clearQueue(__CLASS__);
            $result['Statistics']=$this->oc['SourcePot\Datapool\Foundation\Database']->statistic2matrix();
        } else {
            $stepIndex=$base['stepIndex'];
            $result['Statistic']['Step']['value']=($stepIndex+1).': '.self::STEPS[$stepIndex];
            $method=self::STEPS_METHOD[$stepIndex];
            foreach($this->oc['SourcePot\Datapool\Foundation\Queue']->dequeueEntry(__CLASS__,$stepIndex) as $cacheEntry){
                if (empty($cacheEntry)){continue;}
                $cacheEntry=$this->$method($base,$cacheEntry);
                if ($cacheEntry){
                    $this->oc['SourcePot\Datapool\Foundation\Queue']->enqueueEntry(__CLASS__,$stepIndex+1,$cacheEntry);
                }
                // check processing time
                $result['Statistic']['Entries']['value']++;
                if (hrtime(TRUE)-$base['Script start timestamp']>self::MAX_PROC_TIME){break;}
            }
        }
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
        $paramsGeneric=current($base['processingparamsgeneric'])['Content'];
        $skipFamily=($paramsGeneric['If not granted']=='skip' && empty($family['Info']['isGranted']));
        if (empty($family['Family']) || $skipFamily){
            // skip family
        } else {
            // store family
            $entry=array('Read'=>'ALL_MEMBER_R','Write'=>'ALL_CONTENTADMIN_R','Content'=>$family);
            $this->oc['SourcePot\Datapool\Foundation\Queue']->enqueueEntry(__CLASS__,0,$entry);
            $family['familyCount']++;
        }
        $family=array('familyCount'=>$family['familyCount'],'Family'=>'');
        return $family;
    }

    private function addCosts($base,$cacheEntry):array
    {
        $cacheEntry['Params'][__FUNCTION__]=$cacheEntry['Params'][__FUNCTION__]??array();
        $paramsCosts=current($base['processingparamscosts'])['Content'];
        $paramsCases=current($base['processingparamscases'])['Content'];
        // add costs
        foreach($this->oc['SourcePot\Datapool\Foundation\Database']->entryIterator($cacheEntry['Content']['Info']['costSelector'],TRUE,'Read') as $costEntry){
            $flatCostEntry=$this->oc['SourcePot\Datapool\Tools\MiscTools']->arr2flat($costEntry);
            $unycomArr=$this->oc['SourcePot\Datapool\Tools\MiscTools']->convert2unycom($flatCostEntry[$paramsCosts['Case reference']]);
            if (!$unycomArr['isValid']){
                $cacheEntry['Params'][__FUNCTION__]=$this->oc['SourcePot\Datapool\Tools\MiscTools']->addArrValuesKeywise($cacheEntry['Params'][__FUNCTION__],array('Errors'=>'Invalid case ref '.$flatCostEntry[$paramsCosts['Case reference']]));
                continue;
            } else if ($unycomArr['Type']=='M'){
                continue;
            }
            $case=$unycomArr['Reference'];
            $cacheEntry['Content']['Info']['sum']+=$flatCostEntry[$paramsCosts['Cost record amount']];
            if (isset($cacheEntry['Content'][$case])){
                // cost record matches family case
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
                    $cacheEntry['Params'][__FUNCTION__]=$this->oc['SourcePot\Datapool\Tools\MiscTools']->addArrValuesKeywise($cacheEntry['Params'][__FUNCTION__],array('Errors'=>'Invalid bin'.$bin.' '.$case));
                }
            } else {
                // cost record does not match any family case
                $cacheEntry['Params'][__FUNCTION__]=$this->oc['SourcePot\Datapool\Tools\MiscTools']->addArrValuesKeywise($cacheEntry['Params'][__FUNCTION__],array('Errors'=>'Invalid case cost record '.$case));
            }
        }
        $cacheEntry['Date']=$this->oc['SourcePot\Datapool\Tools\MiscTools']->getDateTime();
        return $cacheEntry;
    }

    private function addFamilyMeta($base,$cacheEntry):array
    {
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
        $cacheEntry['Date']=$this->oc['SourcePot\Datapool\Tools\MiscTools']->getDateTime();
        return $cacheEntry;
    }

    private function createStatistics($base,$cacheEntry)
    {
        $paramsGeneric=current($base['processingparamsgeneric'])['Content'];
        // add cases to statistics session
        foreach($cacheEntry['Content'] as $case=>$caseArr){
            // skip if not a patent case
            if ($case=='Info' || $case=='familyCount' || $case=='Family' || $case=='Family' || $case=='Source' || $case=='EntryId' || $case=='processedStep'){continue;}
            // skip if not granted or costs missing
            if ($caseArr['year granted']>30 || $caseArr['sumTillGrant']==0){continue;}
            // create cost categories
            $categoryCosts=implode(' | ',array_keys($cacheEntry['Content']['Info']['categoryCosts']));
            if (empty($paramsGeneric['Costs needle alias'])){$costsAlias=$paramsGeneric['Category costs needle'];} else {$costsAlias=$paramsGeneric['Costs needle alias'];}
            if (empty($paramsGeneric['Category costs needle'])){
                $categoryCostsHash=substr($categoryCosts,0,20);
            } else if (stripos($categoryCosts,$paramsGeneric['Category costs needle'])!==FALSE){
                $categoryCostsHash='is_'.$costsAlias;
            } else {
                $categoryCostsHash='not_'.$costsAlias;
            }
            // create case categories
            $categoryCases=$cacheEntry['Content']['Info']['categoryCases'];
            if (empty($paramsGeneric['Cases needle alias'])){$casesAlias=$paramsGeneric['Category cases needle'];} else {$casesAlias=$paramsGeneric['Cases needle alias'];}
            if (empty($paramsGeneric['Category cases needle'])){
                $categoryCasesHash=substr($categoryCases,0,20);
            } else if (stripos($categoryCases,$paramsGeneric['Category cases needle'])!==FALSE){
                $categoryCasesHash='is_'.$casesAlias;
            } else {
                $categoryCasesHash='not_'.$casesAlias;
            }
            // create entry at target
            $entry=$base['targetSelector'];
            $entry['Group']=__CLASS__.'|statistic|tmp';
            $entry['Folder']=$entry['Folder']??$categoryCasesHash;
            $entry['Name']=$categoryCasesHash.' | '.$categoryCostsHash;
            $entry['Name'].='||'.str_pad(strval($caseArr['age years']),2,"0",STR_PAD_LEFT).' | '.$caseArr['type'];
            $entry=$this->oc['SourcePot\Datapool\Tools\MiscTools']->addEntryId($entry,array('Group','Folder','Name'),'0','',FALSE);
            $entry['Read']='ALL_MEMBER_R';
            $entry['Write']='ALL_CONTENTADMIN_R';
            $entry['Content']=array('sumTillGrant'=>array(),'year granted'=>array(),'categoryCosts'=>'');
            $entry=$this->oc['SourcePot\Datapool\Foundation\Database']->entryByIdCreateIfMissing($entry,TRUE);
            $entry['Content']['sumTillGrant'][]=$caseArr['sumTillGrant'];
            $entry['Content']['year granted'][]=$caseArr['year granted'];
            $entry['Content']['Type']=$caseArr['type'];
            $entry['Content']['Age']=$caseArr['age years'];
            $entry['Content']['Category cases']=$categoryCases;
            $entry['Content']['Category costs']=$categoryCosts;
            $entry['Content']['Category cases hash']=$categoryCasesHash;
            $entry['Content']['Category costs hash']=$categoryCostsHash;
            $entry['Date']=$this->oc['SourcePot\Datapool\Tools\MiscTools']->getDateTime();
            $entry=$this->oc['SourcePot\Datapool\Foundation\Database']->updateEntry($entry,TRUE);
        }
        return FALSE;
    }

    private function finalizeStatistics($base):int
    {
        $entry=array('rowCount'=>0);
        $selector=$base['targetSelector'];
        $selector['Group']=__CLASS__.'|statistic|tmp';
        foreach($this->oc['SourcePot\Datapool\Foundation\Database']->entryIterator($selector,TRUE,'Read','Name',TRUE) as $entry){
            $entry=array_merge($entry,$base['targetSelector']);
            $entry['Content']['Avg. sum grant']=0;
            $entry['Content']['Max sum grant']=FALSE;
            $entry['Content']['Min sum grant']=FALSE;
            $entry['Content']['Avg. years grant']=0;
            $entry['Content']['Max years grant']=FALSE;
            $entry['Content']['Min years grant']=FALSE;
            foreach($entry['Content']['sumTillGrant'] as $index=>$sumTillGrant){
                $entry['Content']['Avg. sum grant']+=$sumTillGrant;
                $entry['Content']['Avg. years grant']+=$entry['Content']['year granted'][$index];
                if ($entry['Content']['Max sum grant']===FALSE || $entry['Content']['Max sum grant']<$sumTillGrant){$entry['Content']['Max sum grant']=$sumTillGrant;}
                if ($entry['Content']['Min sum grant']===FALSE || $entry['Content']['Min sum grant']>$sumTillGrant){$entry['Content']['Min sum grant']=$sumTillGrant;}
                if ($entry['Content']['Max years grant']===FALSE || $entry['Content']['Max years grant']<$entry['Content']['year granted'][$index]){$entry['Content']['Max years grant']=$entry['Content']['year granted'][$index];}
                if ($entry['Content']['Min years grant']===FALSE || $entry['Content']['Min years grant']>$entry['Content']['year granted'][$index]){$entry['Content']['Min years grant']=$entry['Content']['year granted'][$index];}
            }
            $entry['Content']['Cases']=count($entry['Content']['year granted']);
            if (!empty($entry['Content']['Cases'])){
                $entry['Content']['Avg. sum grant']=round($entry['Content']['Avg. sum grant']/$entry['Content']['Cases'],2);
                $entry['Content']['Avg. years grant']=round($entry['Content']['Avg. years grant']/$entry['Content']['Cases']);
            }
            $this->oc['SourcePot\Datapool\Foundation\Database']->updateEntry($entry,TRUE);
        }
        return $entry['rowCount'];
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