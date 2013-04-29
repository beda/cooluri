<?php
/**
 This file is part of CoolUri.

 CoolUri is free software: you can redistribute it and/or modify
 it under the terms of the GNU General Public License as published by
 the Free Software Foundation, either version 3 of the License, or
 (at your option) any later version.

 CoolUri is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.

 You should have received a copy of the GNU General Public License
 along with CoolUri. If not, see <http://www.gnu.org/licenses/>.
 */

class Link_Translate {

    public static $conf = null;

    private static $coolParamsKeys = false;

    private static $instance = null;

    public static $uri = Array();

    public function __construct($xmlconffile = 'CoolUriConf.xml') {
        $conf = new SimpleXMLElement(file_get_contents($xmlconffile));
        self::$conf = $conf;
    }

    public static function getInstance($xmlconffile = 'CoolUriConf.xml') {
        if (!self::$instance) {
            self::$instance = new Link_Translate($xmlconffile);
        }
        return self::$instance;
    }

    private function getExtractedUri($uri) {
        // composer URI from a variable (usually compose from $_SERVER array)
        if (!empty(self::$conf->uri)) {
            $var = empty(self::$conf->uri->var)?'_SERVER':(string)self::$conf->uri->var;
            if (!empty(self::$conf->uri->part)) {
                $uri = '';
                $var = $GLOBALS[$var];
                foreach (self::$conf->uri->part as $p) {
                    $uri .= $var[(string)$p];
                }
            } else {
                $uri = $GLOBALS[$var];
            }
        }
        return $uri;
    }

    private function removeUriParts($uri) {
        if (!empty(self::$conf->removeparts) && !empty(self::$conf->removeparts->part)) {
            $originaluri = $uri;
            foreach (self::$conf->removeparts->part as $p) {
                if (!empty($p['regexp']) && $p['regexp']==1) { // there's a regexp
                    $uri = preg_replace('~'.(string)$p.'~','',$uri);
                } else {
                    $uri = str_replace((string)$p,'',$uri);
                }
            }
            // if something was stripped (and something is still left, redirect is needed)
            if (!empty(self::$conf->removeparts['redirect']) && self::$conf->removeparts['redirect']==1)
            if (!empty($uri) && $uri!=$originaluri) Link_Func::redirect(Link_Func::prepareforRedirect($uri,self::$conf));
        }
        return $uri;
    }

    private function removeFixes($uri) {
        $temp = explode('?',$uri);
        if (!empty(self::$conf->urlsuffix)) {
            $temp[0] = preg_replace('~'.Link_Func::addregexpslashes((string)self::$conf->urlsuffix).'$~','',$temp[0]);
        }
        if (!empty(self::$conf->urlprefix)) {
            $temp[0] = preg_replace('~^'.Link_Func::addregexpslashes((string)self::$conf->urlprefix).'~','',$temp[0]);
        }
        $uri = implode('?',$temp);
        return $uri;
    }

    private function lookUpInCache($uri) {
        $cachedparams = null;
        if (!empty(self::$conf->cache) && !empty(self::$conf->cache->usecache) && (string)self::$conf->cache->usecache==1) {
            $tp = Link_Func::getTablesPrefix(self::$conf);
            $db = Link_DB::getInstance();
             
            // let's have a look into the cache, we'll look for all possibiltes (meaning trainling slash)
            $tempuri = explode('?',$uri);
             
            $tempuri[0] = Link_Func::prepareLinkForCache($tempuri[0],self::$conf);

            $xuri = $tempuri[0];
            $tempuri[0] = preg_match('~/$~',$tempuri[0])?substr($tempuri[0],0,strlen($tempuri[0])-1):$tempuri[0].'/'; // add or remove trailing slash

            if (!empty(self::$conf->cache->cacheparams) && self::$conf->cache->cacheparams==1) {
                $tempurix = implode('?',$tempuri);
                $xuri = $uri;
            } else {
                $tempurix = $tempuri[0];
            }

            $q = $db->query('SELECT * FROM '.$tp.'cache WHERE url='.$db->escape($xuri).' OR url='.$db->escape($tempurix));
            $row = $db->fetch($q);
            if ($row) {
                if (strcmp($row['url'],$xuri)!==0) { // we've got our $tempuri, not $url -> let's redirect
                    Link_Func::redirect(Link_Func::prepareforRedirect($row['url'].(empty($tempuri[1])?'':'?'.$tempuri[1]),self::$conf));
                } else {
                    $cachedparams = Link_Func::cache2params($row['params']);
                }
            } else {
                $vf = '';
                if (isset(self::$conf->cache->cool2params->oldlinksvalidfor))
                $vf = ' AND DATEDIFF(NOW(),'.$tp.'oldlinks.tstamp)<'.(string)self::$conf->cache->cool2params->oldlinksvalidfor;
                $q = $db->query('SELECT '.$tp.'cache.url AS oldlink FROM '.$tp.'oldlinks  LEFT JOIN '.$tp.'cache ON '.$tp.'oldlinks.link_id='.$tp.'cache.id WHERE ('.$tp.'oldlinks.url='.$db->escape($xuri).' OR '.$tp.'oldlinks.url='.$db->escape($tempurix).')'.$vf);
                $row = $db->fetch($q);
                if ($row) {
                    Link_Func::redirect(Link_Func::prepareforRedirect($row['oldlink'].(empty($tempuri[1])?'':'?'.$tempuri[1]),self::$conf),301);
                } elseif (empty(self::$conf->cache->cool2params->translateifnotfound) || self::$conf->cache->cool2params->translateifnotfound!=1) {
                    Link_Func::pageNotFound(self::$conf);
                }
            }
        } // end cache
        return $cachedparams;
    }

    public function cool2params($uri = '') {
        // check if coolUris are active, if so, proceed with translation
        if (!empty(self::$conf->cooluris) && self::$conf->cooluris==1) {

            $uri = $this->getExtractedUri($uri);
            // now we have in $uri our URI to parse
            // let's remove uninteresting parts (those are not even cached)
            $uri = $this->removeUriParts($uri);
            $uri = $this->removeFixes($uri);
            // now we remove opening slash
            $uri = preg_replace('~^/*~','',$uri);
            if (empty($uri)) return;

            // first let's look into the caches
            $cachedparams = $this->lookUpInCache($uri);

            // for major use of CoolUri - TYPO3, the rest is not called
            // leaving refactoring for future ;)

            //now we have a uri which will be parsed (without unwanted stuff)
            $finaluriparts = Array();
            if (!empty($uri) && empty($cachedparams)) {
                // now we remove trailing slash
                $uri = preg_replace('~/*$~','',$uri);

                $coolpart = null;
                $dirtypart = null;
                $temp = explode('?',$uri);
                if (isset($temp[0])) $coolpart = $temp[0];
                if (isset($temp[1])) $dirtypart = $temp[1];

                if (!empty($coolpart)) {
                    $pathsep = '';
                    if (!empty(self::$conf->pathseparators) && !empty(self::$conf->pathseparators->separator)) {
                        foreach (self::$conf->pathseparators->separator as $sep) {
                            $pathsep .= (string)$sep;
                        }
                    } else {
                        $pathsep = '/';
                    }
                    $coolparts = preg_split('~['.$pathsep.']~',$coolpart);

                    $coolparts = Link_Func::clearGETArray($coolparts);

                    // at first we go through the predefined parts
                    if (!empty(self::$conf->predefinedparts) && !empty(self::$conf->predefinedparts->part)) {
                        foreach (self::$conf->predefinedparts->part as $part) {
                            foreach ($coolparts as $ck => $cp) {

                                $par = false;
                                if (!empty($part['regexp']) && $part['regexp']==1) {
                                    if (preg_match('~^'.$part['key'].'$~', $cp)) {
                                        $par = preg_replace('~^'.$part['key'].'$~',(string)$part->value,$cp);
                                    }
                                } else {
                                    if ($part['key']==$cp) $par = (string)$part->value;
                                }
                                // we found a match in predef parts
                                if ($par) {

                                    // first we find out if it's possible to find anything in the db
                                    $cantranslate = true;
                                    if (!empty($part->lookindb->translatefromif)) {
                                        $cantranslate = Link_Func::constraint($par,$part->lookindb->translatefromif);
                                    }
                                    // we don't look into db for result
                                    if (empty($part->lookindb) || !$cantranslate) {
                                        $finaluriparts[(string)$part->parameter] = $par;
                                        // we do
                                    } else {
                                        $db = Link_DB::getInstance();
                                        $res = $db->query(preg_replace('~^'.$db->escape($part['key']).'$~',(string)$part->lookindb->from,$cp));
                                        $row = $db->fetch_row($res);
                                        // we return value only if we found something
                                        if (!empty($row[0])) $finaluriparts[(string)$part->parameter] = $row[0];
                                    }

                                    // we found a match, so we throw out this cool part, no matter if we got a result
                                    unset($coolparts[$ck]);
                                }

                            }
                        }
                    } // end predefined parts

                    // find stuff in a valuemaps
                    if (!empty(self::$conf->valuemaps) && !empty(self::$conf->valuemaps->valuemap)) {
                        foreach (self::$conf->valuemaps->valuemap as $vm) {
                            if (!empty($vm->value)) {
                                foreach ($vm->value as $val) {
                                    if (in_array((string)$val['key'],$coolparts)) {
                                        $finaluriparts[(string)$vm->parameter] = (string)$val;
                                        $key = array_search((string)$val['key'],$coolparts);
                                        unset($coolparts[$key]);
                                    }
                                }
                            }
                        }
                    } // end valuemaps

                    $nottranslated = Array();
                    // something's still left
                    if (!empty($coolparts) && !empty(self::$conf->uriparts) && !empty(self::$conf->uriparts->part)) {

                        $lastonthepath = null; // here will be kept last part of the pagepath

                        // we'll match cool uri against array
                        for ($i=0,$j=0; $i<count($coolparts) && $j<count(self::$conf->uriparts->part); $i++,$j++) {
                            if (empty($coolparts[$i])) continue; // we don't have a item on this key, shouldn't happen

                            // if a part is not required and next (static) part matches, we skip to it
                            // i.e. category.example.com vs. example.com (in this case "example" would be
                            // considered for a category otherwise)
                            if (!empty(self::$conf->uriparts->part[$j]['notrequired']) && self::$conf->uriparts->part[$j]['notrequired']==1
                            && !empty(self::$conf->uriparts->part[$j+1]) && !empty(self::$conf->uriparts->part[$j+1]['static'])
                            && self::$conf->uriparts->part[$j+1]['static']==1 && (string)self::$conf->uriparts->part[$j+1]->value==$coolparts[$i]
                            ) {
                                ++$j; // we skip to next key in conf
                            }

                            // if current part is static and matches, we move on
                            // if is static, but doesn't match, we move on
                            if (!empty(self::$conf->uriparts->part[$j]['static']) && self::$conf->uriparts->part[$j]['static']==1) {
                                if ((string)self::$conf->uriparts->part[$j]->value==$coolparts[$i]) {
                                    continue;
                                } else {
                                    ++$j;
                                }
                            }

                            // this should be a dynamic param
                            if (!empty(self::$conf->uriparts->part[$j]->parameter)) {
                                // we preset the variable, if may change after
                                $finaluriparts[(string)self::$conf->uriparts->part[$j]->parameter] = $coolparts[$i];

                                // first we find out if it's possible to find anything in the db
                                $cantranslate = true;
                                if (!empty(self::$conf->uriparts->part[$j]->lookindb->translatefromif)) {
                                    $cantranslate = Link_Func::constraint($coolparts[$i],self::$conf->uriparts->part[$j]->lookindb->translatefromif);
                                }

                                if (!empty(self::$conf->uriparts->part[$j]->lookindb) && $cantranslate) {
                                    $proceed = true;
                                    $sql = self::$conf->uriparts->part[$j]->lookindb->from;
                                    $sql = str_replace('$1',$coolparts[$i],$sql);
                                    /* undocumented */
                                    if (preg_match_all('~\$[^ ]+ ~',$sql.' ',$vars)) {
                                        if (!empty($vars[0])) {
                                            foreach ($vars[0] as $var) {
                                                if (isset($nottranslated[substr(trim($var),1)])) $proceed = false; // this var wasn't found in db b4, no need to try to translate
                                                else {
                                                    if (empty($finaluriparts[substr(trim($var),1)])) continue; // subst not found - query res would be empty
                                                    else $sql = str_replace(trim($var),$db->escape($finaluriparts[substr(trim($var),1)]),$sql);
                                                }
                                            }
                                        }
                                    }
                                    /* undocumented */
                                    if ($proceed) {
                                        $db = Link_DB::getInstance();
                                        $res = $db->query($sql);
                                        if (!$db->error() && $db->num_rows($res)>0) { // no match found - not translating
                                            $row = $db->fetch_row($res);
                                            $finaluriparts[(string)self::$conf->uriparts->part[$j]->parameter] = $row[0];
                                        } else {
                                            $nottranslated[(string)self::$conf->uriparts->part[$j]->parameter] = true; // this param wasn't translated
                                        }
                                    } else {
                                        $nottranslated[(string)self::$conf->uriparts->part[$j]->parameter] = true; // this param wasn't translated
                                    }
                                } elseif (!empty(self::$conf->pagepath) && !empty(self::$conf->uriparts->part[$j]['pagepath']) && self::$conf->uriparts->part[$j]['pagepath']=='1') {
                                    // this param is part of the pagepath, let's try that
                                    if ($lastonthepath==null || !empty($finaluriparts[$lastonthepath])) {
                                        $db = Link_DB::getInstance();
                                        $sql = 'SELECT '.(string)self::$conf->pagepath->id.' FROM '.(string)self::$conf->pagepath->table;
                                        $sql .= ' WHERE '.(string)self::$conf->pagepath->alias.'=\''.$db->escape($coolparts[$i]).'\'';
                                        if ($lastonthepath==null) $sql .= ' AND '.(string)self::$conf->pagepath->start->param.'='.(string)self::$conf->pagepath->start->value;
                                        else $sql .= ' AND '.(string)self::$conf->pagepath->connection.'='.$db->escape($finaluriparts[$lastonthepath]);
                                        $res = $db->query($sql);
                                        if (!$db->error() && $db->num_rows($res)>0) { // no match found - not translating
                                            $row = $db->fetch_row($res);
                                            $finaluriparts[(string)self::$conf->uriparts->part[$j]->parameter] = $row[0];
                                        } else {
                                            $nottranslated[(string)self::$conf->uriparts->part[$j]->parameter] = true; // this param wasn't translated
                                        }
                                        $lastonthepath = (string)self::$conf->uriparts->part[$j]->parameter;
                                    } else {
                                        $nottranslated[(string)self::$conf->uriparts->part[$j]->parameter] = true; // this param wasn't translated
                                    }
                                }
                            }
                        }
                    } // end uriparts

                }
                // Cool part done, let's add dirty part
                if (!empty($dirtypart)) {
                    $finaluriparts = array_merge($finaluriparts,Link_Func::convertQuerystringToArray($dirtypart));
                }
                // Now we'll compose our return pagepath to one variable (i.e. id could be one of cid,sid,lid,tid)
                if (!empty(self::$conf->pagepath->saveto)) {
                    // first we'll set a default value (if not set already)
                    if (empty($finaluriparts[(string)self::$conf->pagepath->saveto]))
                    $finaluriparts[(string)self::$conf->pagepath->saveto] = (string)self::$conf->pagepath->default;

                    // let's modify param constraints array a bit:
                    $paramconstraints = Array();
                    if (!empty(self::$conf->uriparts->paramconstraints->paramconstraint)) {
                        foreach (self::$conf->uriparts->paramconstraints->paramconstraint as $pc) {
                            $paramconstraints[(string)$pc['param']] = $pc;
                        }
                    }

                    $rqok = true;
                    // first we'll check if a required param is OK
                    if (!empty(self::$conf->pagepath->required) && !empty(self::$conf->pagepath->required->param)) {
                        foreach (self::$conf->pagepath->required->param as $p) {
                            $p = (string)$p;
                            if (empty($finaluriparts[$p]))
                            $rqok = false;
                            elseif (!empty($paramconstraints[$p]) && !Link_Func::constraint($finaluriparts[$p],$paramconstraints[$p]))
                            $rqok = false;
                            elseif (!empty(self::$conf->pagepath->allparamconstraints)
                            && !Link_Func::constraint($finaluriparts[$p],self::$conf->pagepath->allparamconstraints))
                            $rqok = false;
                        }
                    }

                    // now we'll go through all params, check if they're ok and eventually set the final value
                    if ($rqok) { // only if required is OK
                        $px = Array();
                        foreach (self::$conf->uriparts->part as $p) {
                            $px[] = $p;
                        }
                        foreach (array_reverse($px) as $p) {
                            if (empty($p['pagepath']) || $p['pagepath']!='1') continue;
                            $p = (string)$p->parameter;
                            if (!empty($finaluriparts[$p])) {
                                if (!empty($paramconstraints[$p]) && !Link_Func::constraint($finaluriparts[$p],$paramconstraints[$p]))
                                continue;

                                if (!empty(self::$conf->pagepath->allparamconstraints)
                                && !Link_Func::constraint($finaluriparts[$p],self::$conf->pagepath->allparamconstraints))
                                continue;

                                // all tests passed, this is what we're looking for
                                $finaluriparts[(string)self::$conf->pagepath->saveto] = $finaluriparts[$p];
                                break;
                            }
                        }
                    }
                }

            } // end !empty($uri) && empty($cachedparams)

            $temp =  empty($cachedparams)?$finaluriparts:$cachedparams;
            $temp = array_map('urldecode',$temp);

            self::$uri = $temp;
            $res = array_merge($_GET,$temp);
            if (!empty(self::$conf->savetranslationto)) {
                $x = (string)self::$conf->savetranslationto;
                switch (trim($x)) {
                    case '_REQUEST': $_REQUEST = array_merge($_REQUEST,$res); break;
                    case '_GET': $_GET = array_merge($_GET,$res); break;
                    case '_POST': $_POST = array_merge($_POST,$res); break;
                    default: $GLOBALS[$x] = $res;
                }
            } else
            $_GET = $res;

            $this->uri = $res;

            return $res;
        }
    }

    /////////////////////////////////////////////////////////////////////////////////////////////////////////////

    public function params2coolForRedirect(array $params) {
        return $this->params2cool($params,'',false);
    }


    public function params2cool(array $params, $file = '', $entityampersand = true, $dontconvert = false, $forceUpdate = false) {
        if (!empty(self::$conf->cooluris) && self::$conf->cooluris==1 && !$dontconvert) {
            // if cache is allowed, we'll look for an uri
            $uriFromCache = $this->getCachedUri($params, $forceUpdate);
            if ($uriFromCache!=null) {
                if (is_array($uriFromCache)) {
                    // not good, still needs to be refactored
                    // these paramters are read in the end to
                    // refresh cache
                    $updatecacheid = $uriFromCache[0];
                    $cacheduri = $uriFromCache[1];
                } else {
                    return $uriFromCache;
                }
            }
            $originalparams = $params;
            $predefparts = $this->translatePredefinedParams($params,$originalparams);
            $predefparts = array_merge($predefparts, $this->translateValuemaps($params));
            // now the pagepath
            $translatedpagepath = Array();
            $pagepath = Array();
            $this->translatePagepath($params, $pagepath, $translatedpagepath, $originalparams);
            $this->translateUriparts($params, $pagepath, $translatedpagepath, $originalparams);
            $statics = $this->getStatics();
            // we need list of separators
            $seps = $this->getSeparators();
            $paramsinorder = Array();
            $path = $this->getPath($paramsinorder,$predefparts,$translatedpagepath,$statics,$seps);
            $vm = $this->getAppendedValuemaps($predefparts, $params, $paramsinorder);
            $pp = $this->getAppendedPredefinedparts($predefparts, $params, $paramsinorder);
            $tp = $this->getAppendedUriparts($params, $paramsinorder, $translatedpagepath);
            $pagep = $this->getAppendedPagepath($pagepath);
            $partorder = $this->getPartOrder();
            $path = $this->getSortedPath($path,$partorder,$tp,$vm,$pp,$pagep);
            $path = $this->saveInCache($params,$path,$originalparams,$updatecacheid,$cacheduri,$entityampersand);
            $params = $this->transformParamsToQS($params, $entityampersand);            
            return Link_Func::prepareforOutput($path,self::$conf).(empty($params)?'':$params);
        } else {
            return (empty($file)?$_SERVER['PHP_SELF']:$file).(empty($params)?'':'?'.http_build_query($params,'',$entityampersand?'&amp;':'&'));
        }
    }


    private function getCachedUri($params, $forceUpdate) {
        if (!empty(self::$conf->cache) && !empty(self::$conf->cache->usecache) && self::$conf->cache->usecache==1) {
            $tp = Link_Func::getTablesPrefix(self::$conf);
            $db = Link_DB::getInstance();
            // cache is valid for only a sort period of time, after that time we need to do a recheck
            $checkfornew = !empty(self::$conf->cache->params2cool)&&!empty(self::$conf->cache->params2cool->checkforchangeevery)?(string)self::$conf->cache->params2cool->checkforchangeevery:0;
            $originalparams = $params;

            // we don't cache params
            if (empty(self::$conf->cache->cacheparams) || self::$conf->cache->cacheparams!=1) {
                if (!self::$coolParamsKeys) {
                    self::$coolParamsKeys = Link_Func::getCoolParams(self::$conf);
                }
                $originalparams = Link_Func::array_intersect_key($originalparams,self::$coolParamsKeys);
            }
            $cacheQ = Link_Func::prepareParamsForCache($originalparams,$tp);
            $q = $db->query('SELECT *, DATEDIFF(NOW(),tstamp) AS daydiff FROM '.$tp.'cache WHERE params='.$cacheQ);
            $row = $db->fetch($q);

            if ($row) {
                if ($row['daydiff']==NULL) {
                    $row['daydiff'] = 2147483647; // daydiff isn't set, we force new check
                }

                if (($row['daydiff']>=$checkfornew && $row['sticky']==0) || $forceUpdate) {
                    $updatecacheid = $row['id'];
                    $cacheduri = $row['url'];
                    Link_Log::log('URL from cache 1 ('.$updatecacheid.'): '.$cacheduri);
                    return Array($updatecacheid, $cacheduri);
                } else {
                    $qs = '';
                    if (empty(self::$conf->cache->cacheparams) || self::$conf->cache->cacheparams!=1) {
                        $qsp = Link_Func::array_diff_key($params,$originalparams);
                        if (!empty($qsp)) {
                            foreach ($qsp as $k=>$v) $qsp[$k] = $k.'='.$v;
                            $qs = '?'.implode('&',$qsp);
                        }
                    }
                    Link_Log::log('URL from cache 2: '.$row['url']);
                    return Link_Func::prepareforOutput($row['url'],self::$conf).$qs; // uri found in cache
                }
            }
        }  // end cache
        return null;
    }

    private function translatePredefinedParams(&$params,&$originalparams) {
        $predefparts = Array();
        if (!empty(self::$conf->predefinedparts) && !empty(self::$conf->predefinedparts->part)) {

            // first let's translate predefenied params
            foreach (self::$conf->predefinedparts->part as $ppart) {
                if (isset($params[(string)$ppart->parameter])) {
                    $uf = Link_Func::user_func($ppart,$params[(string)$ppart->parameter]);
                    if ($uf!==FALSE) {
                        $predefparts[(string)$ppart->parameter] = $uf;
                    } elseif (!empty($ppart['regexp']) && $ppart['regexp']==1) {
                        $predefparts[(string)$ppart->parameter] = preg_replace('~\([^)]+\)~',empty($ppart->lookindb)?$params[(string)$ppart->parameter]:Link_Func::lookindb($ppart->lookindb->to,$params[(string)$ppart->parameter],$ppart->lookindb,$originalparams),$ppart['key']);
                    } elseif ($ppart->value==$params[(string)$ppart->parameter]) {
                        $predefparts[(string)$ppart->parameter] = empty($ppart->lookindb)?(string)$ppart['key']:Link_Func::lookindb($ppart->lookindb->to,$params[(string)$ppart->parameter],$ppart->lookindb,$originalparams);
                    }
                    unset($params[(string)$ppart->parameter]);
                }
            }
        } // end predefinedparts
        return $predefparts;
    }

    private function translateValuemaps(&$params) {
        //valuemaps (iterate thru values in maps)
        $predefparts = Array();
        if (!empty(self::$conf->valuemaps) && !empty(self::$conf->valuemaps->valuemap)) {
            foreach (self::$conf->valuemaps->valuemap as $vm) {
                if (isset($params[(string)$vm->parameter])) {
                    foreach ($vm->value as $val) {
                        if ((string)$val==$params[(string)$vm->parameter]) {
                            $predefparts[(string)$vm->parameter] = (string)$val['key']; // let's just add it to the predeparts array
                            unset($params[(string)$vm->parameter]);
                        }
                    }
                }
            }
        }
        return $predefparts;
    }

    private function translatePagepath(&$params, &$pagepath, &$translatedpagepath,&$originalparams) {
        if (!empty(self::$conf->pagepath) && !empty(self::$conf->pagepath->saveto) && !empty($params[(string)self::$conf->pagepath->saveto])) {

            $uf = Link_Func::user_func(self::$conf->pagepath,$originalparams);
            if ($uf===FALSE) {
                $curid = $params[(string)self::$conf->pagepath->saveto];
                $result = true;
                $lastpid = null;
                $db = Link_DB::getInstance();
                $limit = 10;
                while ($limit>0 && (empty(self::$conf->pagepath->idconstraint)?$result:($result && Link_Func::constraint($curid,self::$conf->pagepath->idconstraint)))) {
                    --$limit;
                    if (empty(self::$conf->pagepath->alias)) $sel = (string)self::$conf->pagepath->title;
                    else $sel = (string)self::$conf->pagepath->alias;

                    $sql = 'SELECT '.(string)self::$conf->pagepath->connection.','.$sel.'
                            FROM '.(string)self::$conf->pagepath->table.' WHERE '.(string)self::$conf->pagepath->id.'='.$db->escape($lastpid==null?$params[(string)self::$conf->pagepath->saveto]:$lastpid);
                    if (!empty(self::$conf->pagepath->additionalWhere)) {
                        $sql .= ' '.(string)self::$conf->pagepath->additionalWhere;
                    }

                    $res = $db->query($sql);
                    if ($db->error() || !$res) {
                        $result = false; continue;
                    }
                    $row = $db->fetch_row($res);
                    if (!$row) {
                        $result = false; continue;
                    }

                    if (empty(self::$conf->pagepath->alias)) { // we need to convert title to a uri, if alias is not set
                        $val = $row[1];
                        $k = 2;
                        while (empty($val) && isset($row[$k])) { // there may be more columns we want to have a look at
                            $val = $row[$k];
                            ++$k;
                        }
                        if (!empty(self::$conf->sanitize) && self::$conf->sanitize==1) {
                            $pagepath[] = Link_Func::sanitize_title_with_dashes($val);
                        } else {
                            $pagepath[] = Link_Func::URLize($val);
                        }

                    } else
                    $pagepath[] = $row[1];
                    $lastpid = $row[0];
                }
            } else {
                $pagepath = $uf;
            }
            unset($params[(string)self::$conf->pagepath->saveto]);
            $pagepath = array_reverse($pagepath);
        } // end pagepath
    }

    private function translateUriparts(&$params, &$pagepath, &$translatedpagepath,&$originalparams) {
        if (!empty(self::$conf->uriparts) && !empty(self::$conf->uriparts->part)) { // a path found
            $counter = 0;
            foreach (self::$conf->uriparts->part as $pp) {
                if (isset($params[(string)$pp->parameter])) {
                    $uf = Link_Func::user_func($pp,$params[(string)$pp->parameter]);
                    if ($uf!==FALSE) {
                        $translatedpagepath[(string)$pp->parameter] = $uf;
                    } else {
                        $translatedpagepath[(string)$pp->parameter] = (empty($pp->lookindb)?$params[(string)$pp->parameter]:Link_Func::lookindb($pp->lookindb->to,$params[(string)$pp->parameter],$pp->lookindb,$originalparams));
                    }
                    unset($params[(string)$pp->parameter]);
                } elseif (!empty($pp['pagepath']) && $pp['pagepath']==1 && !empty($pagepath[$counter])) {
                    //if (!empty($pagepath[$counter])) {
                    $translatedpagepath[(string)$pp->parameter] = $pagepath[$counter];
                    unset($pagepath[$counter]);
                    ++$counter;
                }

            }
        }
    }

    private function getStatics() {
        $statics = Array();
        if (!empty(self::$conf->paramorder) && !empty(self::$conf->paramorder->param)) {
            if (!empty(self::$conf->uriparts) && !empty(self::$conf->uriparts->part)) {
                foreach (self::$conf->uriparts->part as $part) {
                    if (!empty($part['static']) && $part['static']==1) {
                        $statics[(string)$part->value] = (string)$part->value;
                    }
                }
            }
        }
        return $statics;
    }

    private function getSeparators() {
        $seps = Array();
        if (!empty(self::$conf->predefinedparts) && !empty(self::$conf->predefinedparts->part)) {
            foreach (self::$conf->predefinedparts->part as $part) {
                $seps[(string)$part->parameter] = Link_Func::getSeparator($part);
            }
        }
        if (!empty(self::$conf->valuemaps) && !empty(self::$conf->valuemaps->valuemap)) {
            foreach (self::$conf->valuemaps->valuemap as $part) {
                $seps[(string)$part->parameter] = Link_Func::getSeparator($part);
            }
        }
        if (!empty(self::$conf->uriparts) && !empty(self::$conf->uriparts->part)) {
            foreach (self::$conf->uriparts->part as $part) {
                if (!empty($part['static']) && $part['static']==1) {
                    $seps[(string)$part->value] = Link_Func::getSeparator($part);
                }
                else  {
                    $seps[(string)$part->parameter] = Link_Func::getSeparator($part);
                }
            }
        }
        return $seps;
    }

    private function getPath(&$paramsinorder,$predefparts,$translatedpagepath,$statics,$seps) {
        $path = '';
        if (!empty(self::$conf->paramorder) && !empty(self::$conf->paramorder->param)) {
            foreach (self::$conf->paramorder->param as $par) {
                $paramsinorder[(string)$par] = true;
                if (!empty($predefparts[(string)$par])) {
                    $path .= $predefparts[(string)$par].$seps[(string)$par];
                } elseif (!empty($translatedpagepath[(string)$par])) {
                    $path .= $translatedpagepath[(string)$par].$seps[(string)$par];
                } elseif (!empty($statics[(string)$par])) {
                    $path .= $statics[(string)$par].$seps[(string)$par];
                }
            }
        }
        return $path;
    }

    private function getAppendedValuemaps(&$predefparts, &$params, &$paramsinorder) {
        $vm = '';
        if (!empty(self::$conf->valuemaps) && !empty(self::$conf->valuemaps->valuemap)) {
            foreach (self::$conf->valuemaps->valuemap as $part) {
                if (!empty($predefparts[(string)$part->parameter]) && empty($paramsinorder[(string)$part->parameter])) {
                    $vm .= $predefparts[(string)$part->parameter].Link_Func::getSeparator($part);
                    unset($predefparts[(string)$part->parameter]);
                    unset($params[(string)$part->parameter]);
                }
            }
        }
        return $vm;
    }

    private function getAppendedPredefinedparts(&$predefparts, &$params, &$paramsinorder) {
        $pp = '';
        if (!empty(self::$conf->predefinedparts) && !empty(self::$conf->predefinedparts->part)) {
            foreach (self::$conf->predefinedparts->part as $part) {
                if (!empty($predefparts[(string)$part->parameter]) && empty($paramsinorder[(string)$part->parameter])) {
                    $pp .= $predefparts[(string)$part->parameter].Link_Func::getSeparator($part);
                    unset($predefparts[(string)$part->parameter]);
                    unset($params[(string)$part->parameter]);
                }
            }
        }
        return $pp;
    }

    private function getAppendedUriparts(&$params, &$paramsinorder, &$translatedpagepath) {
        $tp = '';
        if (!empty(self::$conf->uriparts) && !empty(self::$conf->uriparts->part)) {
            foreach (self::$conf->uriparts->part as $part) {
                if (!empty($part['static']) && $part['static']==1 && empty($paramsinorder[(string)$part->value])) {
                    $tp .= (string)$part->value.Link_Func::getSeparator($part);
                }
                elseif (isset($translatedpagepath[(string)$part->parameter]) && empty($paramsinorder[(string)$part->parameter])) {
                    $tp .= $translatedpagepath[(string)$part->parameter].Link_Func::getSeparator($part);
                    unset($params[(string)$part->parameter]);
                }
            }
        }
        return $tp;
    }

    private function getSortedPath($path,$partorder,$tp,$vm,$pp,$pagep) {
        foreach ($partorder as $p) {
            switch ($p) {
                case 'uriparts': $path .= $tp; break;
                case 'valuemaps': $path .= $vm; break;
                case 'predefinedparts': $path .= $pp; break;
                case 'pagepath': $path .= $pagep; break;
            }
        }
        return $path;
    }

    private function getPartOrder() {
        if (!empty(self::$conf->partorder) && !empty(self::$conf->partorder->part)) {
            $partorder = Array();
            foreach (self::$conf->partorder->part as $p) {
                $partorder[] = (string)$p;
            }
        } else {
            $partorder = Array('pagepath','uriparts','valuemaps','predefinedparts');
        }
        return $partorder;
    }

    private function transformParamsToQS($params, $entityampersand) {
        if (!empty($params)) {
            foreach ($params as $k=>$v) $params[$k] = $k.'='.$v;
            $params = '?'.implode('&',$params);
            if ($entityampersand) $params = str_replace('&','&amp;',$params);
            return $params;
        }
        return '';
    }

    private function getAppendedPagepath($pagepath) {
        // if pagepath is not empty, that means not all pagepaths were added to $translatepagepath. We'll just add it
        $pagep = '';
        if (!empty(self::$conf->pagepath) && !empty(self::$conf->pagepath->saveto) && !empty($pagepath)) {
            $pagep = implode(Link_Func::getSeparator(),$pagepath).Link_Func::getSeparator();
        }
        return $pagep;
    }

    private function saveInCache($params,$path,$originalparams,$updatecacheid,$cacheduri,$entityampersand) {
        // if cache is allowed, we'll save path to the cache (excluding possible prefix and suffix)
        if (!empty(self::$conf->cache) && !empty(self::$conf->cache->usecache) && self::$conf->cache->usecache==1) {
            $tp = Link_Func::getTablesPrefix(self::$conf);
            $db = Link_DB::getInstance();

            $p = '';
            if (!empty(self::$conf->cache->cacheparams) && self::$conf->cache->cacheparams==1 && !empty($params)) {
                $p = $this->transformParamsToQS($params, $entityampersand);
            }

            $path = Link_Func::prepareLinkForCache($path,self::$conf);
            
            foreach ($params as $k=>$v) {
                unset($originalparams[$k]);
            }
            
            if (!empty($originalparams)) {
                if (!empty($updatecacheid)) {
                    // first we will update the timestamp (so we will now, when the last uri check was)
                    $db->query('UPDATE '.$tp.'cache SET tstamp=NOW() WHERE id='.(int)$updatecacheid);
                    if ($cacheduri!=$path.$p) {
                        // uri is changed, we need to move the old one to the old links
                        $db->query('INSERT INTO '.$tp.'oldlinks(link_id,url) VALUES('.(int)$updatecacheid.','.$db->escape($cacheduri).')');
                        $db->query('UPDATE '.$tp.'cache SET url='.$db->escape($path.$p).' WHERE id='.(int)$updatecacheid);

                        // if the path has changed back, no need to store it in the oldlinks
                        // prevets from overflooding the DB when tampering with configuration
                        $db->query('DELETE FROM '.$tp.'oldlinks WHERE url='.$db->escape($path.$p));

                    }
                } else {
                    $res = $db->query('SELECT * FROM '.$tp.'cache WHERE url='.$db->escape($path.$p));
                    if ($db->num_rows($res)==0) {
                       $db->query('INSERT INTO '.$tp.'cache(url,params,crdatetime) VALUES('.$db->escape($path.$p).','.Link_Func::prepareParamsForCache($originalparams).',NOW())');
                    }
                }
            }
        }
        
        return $path;
    }

    public function GET($var) {
        if (!isset($this->uri[$var])) return false;
        return stripslashes($this->uri[$var]);
    }

    public function sGET($var) {
        return addslashes(self::GET($var));
    }

    public function GETall() {
        return $this->uri;
    }

    public static function replaceAllLinks($string) {

        function replace($link) {
            if (!preg_match('~^http://~',$link[2])) {
                $parts = explode('?',$link[2]);
                if (empty($parts[1])) return $link[0];
                $lt = Link_Translate::getInstance();
                $link[2] = str_replace('&amp;','&',$link[2]);
                $link[2] = $lt->params2cool(Link_Func::convertQueryStringToArray($parts[1]),$parts[0]);
                unset($link[0]);
                return implode('',$link);
            } else {
                return $link[0];
            }
        }

        $string = preg_replace_callback('~(href=[\'"])([^\'"]+)([\'"])~', 'replace', $string);
        $string = preg_replace_callback('~(method=[\'"])([^\'"]+)([\'"])~', 'replace', $string);

        return $string;
    }

}

?>
