<?php
if(!defined('DOKU_INC')) define('DOKU_INC',realpath(dirname(__FILE__).'/../../../').'/');
if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');
require_once(DOKU_PLUGIN.'action.php');
global $conf;

$default_english_file = DOKU_PLUGIN . 'fckg/action/lang/en.php';
require_once($default_english_file);

if(isset($conf['lang']) && $conf['lang'] != 'en' ) {
  $default_lang_file = DOKU_PLUGIN . 'fckg/action/lang/' . $conf['lang'] . '.php';
  if(file_exists($default_lang_file)) {                                       
    @include($default_lang_file);
  }
}

/**
 * @license    GNU GPLv2 version 2 or later (http://www.gnu.org/licenses/gpl.html)
 * 
 * class       plugin_fckg_edit 
 * @author     Myron Turner <turnermm02@shaw.ca>
 */

class action_plugin_fckg_edit extends DokuWiki_Action_Plugin {
    //store the namespaces for sorting
    var $fck_location = "fckeditor";
    var $helper = false;
    var $fckg_bak_file = "";
    var $debug = false;
    var $test = false;
    var $page_from_template;
    var $draft_found = false;
    var $draft_text;
    /**
     * Constructor
     */
    function action_plugin_fckg_edit()
    {
        $this->setupLocale();
        $this->helper =& plugin_load('helper', 'fckg');
    }


    function register(&$controller)
    {
        global $FCKG_show_preview;
        $FCKG_show_preview = true;

        if(isset($_REQUEST['do']) && $_REQUEST['do'] == 'draft') {
          //return;
        }

        if(isset($_REQUEST['mode']) && $_REQUEST['mode'] == 'dwiki') {
          $FCKG_show_preview = true;
          return;
        }
        elseif(isset($_COOKIE['FCKW_USE'])) {
             preg_match('/_\w+_/',  $_COOKIE['FCKW_USE'], $matches);
             if($matches[0] == '_false_') {
                  $FCKG_show_preview = true;     
                   return;
             }
        }
        $Fck_NmSp = "!!NONSET!!"; 
        if(isset($_COOKIE['FCK_NmSp'])) {
          $Fck_NmSp = $_COOKIE['FCK_NmSp'];
        }
        $dwedit_ns = @$this->getConf('dwedit_ns');
        if(isset($dwedit_ns) && $dwedit_ns) {
            $ns_choices = explode(',',$dwedit_ns);
            foreach($ns_choices as $ns) {
              $ns = trim($ns);
              if(preg_match("/$ns/",$_REQUEST['id']) || preg_match("/$ns/",$Fck_NmSp)) {
                      $FCKG_show_preview = true;     
                       return;
             }
            }
        }
        $controller->register_hook('COMMON_PAGE_FROMTEMPLATE', 'AFTER', $this, 'pagefromtemplate', array());
        $controller->register_hook('COMMON_PAGETPL_LOAD', 'AFTER', $this, 'pagefromtemplate', array());

        $controller->register_hook('TPL_ACT_RENDER', 'BEFORE', $this, 'fckg_edit');
        $controller->register_hook('TPL_METAHEADER_OUTPUT', 'BEFORE', $this, 'fckg_edit_meta');
    }

   /**
    * function pagefromtemplate
    * Capture template text output by Template Event handler instead of pageTemplate()
	* @author  Myron Turner <turnermm02@shaw.ca>     
    *               
    */
    function pagefromtemplate(&$event) {
      if($event->data['tpl']) { 
         $this->page_from_template = $event->data['tpl']; 
      }
    }

    /**
     * fckg_edit_meta 
     *
     * load fck js
     * @author Pierre Spring <pierre.spring@liip.ch>
     * @param mixed $event 
     * @access public
     * @return void
     */
    function fckg_edit_meta(&$event)
    {
        global $ACT;
        // we only change the edit behaviour
        if ($ACT != 'edit'){
            return;
        }
        global $ID;
        global $REV;
        global $INFO;

        $event->data['script'][] = 
            array( 
                'type'=>'text/javascript', 
                'charset'=>'utf-8', 
                '_data'=>'',
                 'src'=>DOKU_BASE.'lib/plugins/fckg/' .$this->fck_location. '/fckeditor.js'
            );

        $event->data['script'][] = 
            array( 
                'type'=>'text/javascript', 
                'charset'=>'utf-8', 
                '_data'=>'',
                 'src'=>DOKU_BASE.'lib/plugins/fckg/scripts/vki_kb.js'
            );

      $ua = strtolower ($_SERVER['HTTP_USER_AGENT']);
      if(strpos($ua, 'msie') !== false) {
          echo "\n" . '<meta http-equiv="X-UA-Compatible" content="IE=EmulateIE8" />' ."\n";     
      }
            
        return;
    }

    /**
     * function    fckg_edit
     * @author     Pierre Spring <pierre.spring@liip.ch>
     * edit screen using fck
     *
     * @param & $event
     * @access public
     * @return void
     */
    function fckg_edit(&$event)
    {
  
        global $INFO;

        // we only change the edit behaviour
        if ($event->data != 'edit') {
            return;
        }
        // load xml and acl
        if (!$this->_preprocess()){
            return;
        }
        // print out the edit screen
        $this->_print();
        // prevent Dokuwiki normal processing of $ACT (it would clean the variable and destroy our 'index' value.
        $event->preventDefault();
        // index command belongs to us, there is no need to hold up Dokuwiki letting other plugins see if its for them
        $event->stopPropagation();
    }
    
   /**
    * function _preprocess
	* @author  Myron Turner <turnermm02@shaw.ca>
    */
    function _preprocess()
    {
        global $ID;
        global $REV;
        global $DATE;
        global $RANGE;
        global $PRE;
        global $SUF;
        global $INFO;
        global $SUM;
        global $lang;
        global $conf;
        global $fckg_lang; 
        //set summary default
        if(!$SUM){
            if($REV){
                $SUM = $lang['restored'];
            }elseif(!$INFO['exists']){
                $SUM = $lang['created'];
            }
        }
        
            if($INFO['exists']){
                if($RANGE){
                    list($PRE,$text,$SUF) = rawWikiSlices($RANGE,$ID,$REV);
                }else{
                    $text = rawWiki($ID,$REV);
                }
            }else{
                //try to load a pagetemplate
                 $text = pageTemplate($ID);
                //Check for text from template event handler
                 if(!$text && $this->page_from_template) $text = $this->page_from_template;
            }

       $this->xhtml = $text;

       $cname = getCacheName($INFO['client'].$ID,'.draft.fckl');
       if(file_exists($cname)) {
          $cdata =  unserialize(io_readFile($cname,false));
          $cdata['text'] = urldecode($cdata['text']);
          preg_match_all("/<\/(.*?)\>/", $cdata['text'],$matches);
          /* exclude drafts saved from preview mode */
          if (!in_array('code', $matches[1]) && !in_array('file', $matches[1]) && !in_array('nowiki', $matches[1])) {
              $this->draft_text = $cdata['text'];
              $this->draft_found = true;
              msg($fckg_lang['draft_msg']) ;
          }
          unlink($cname);
       }

        return true;
    }

   /** 
    * function dw_edit_displayed
    * @author  Myron Turner
    * determines whether or not to show  or hide the
      'DW Edit' button
   */

   function dw_edit_displayed() 
   { 
        global $INFO;

        $dw_edit_display = @$this->getConf('dw_edit_display');
        if(!isset($dw_edit_display))return "";  //version 0. 
        if($dw_edit_display != 'all') {
            $admin_exclusion = false;
            if($dw_edit_display == 'admin' && ($INFO['isadmin'] || $INFO['ismanager']) ) {    
                    $admin_exclusion = true;
            }
            if($dw_edit_display == 'none' || $admin_exclusion === false) {
              return ' style = "display:none"; ';
            }
           return "";
        }
        return "";
      
   }

   /**
    * function _print
    * @author  Myron Turner
    */ 
    function _print()
    {
        global $INFO;
        global $lang;
        global $fckg_lang;
        global $ID;
        global $REV;
        global $DATE;
        global $PRE;
        global $SUF;
        global $SUM;
        $wr = $INFO['writable'];
        if($wr){
           if ($REV) print p_locale_xhtml('editrev');          
           $ro=false;
        }else{
            // check pseudo action 'source'
            if(!actionOK('source')){
                msg('Command disabled: source',-1);
                return false;
            }
            print p_locale_xhtml('read');
            $ro='readonly="readonly"';
        }

        if(!$DATE) $DATE = $INFO['lastmod'];
        $guest_toolbar = $this->getConf('guest_toolbar');
        $guest_media  = $this->getConf('guest_media');
        if(!isset($INFO['userinfo']) && !$guest_toolbar) {        
            
                echo  $this->helper->registerOnLoad(
                    ' fck = new FCKeditor("wiki__text", "100%", "600"); 
                     fck.BasePath = "'.DOKU_BASE.'lib/plugins/fckg/'.$this->fck_location.'/"; 
                     fck.ToolbarSet = "DokuwikiNoGuest";  
                     fck.ReplaceTextarea();'
                     );
        }
        else if(!isset($INFO['userinfo']) && !$guest_media) {            

            echo  $this->helper->registerOnLoad(
                ' fck = new FCKeditor("wiki__text", "100%", "600"); 
                 fck.BasePath = "'.DOKU_BASE.'lib/plugins/fckg/'.$this->fck_location.'/"; 
                 fck.ToolbarSet = "DokuwikiGuest";  
                 fck.ReplaceTextarea();'
                 );
        }
        
        else {
            echo  $this->helper->registerOnLoad(
                ' fck = new FCKeditor("wiki__text", "100%", "600"); 
                 fck.BasePath = "'.DOKU_BASE.'lib/plugins/fckg/'.$this->fck_location.'/"; 
                 fck.ToolbarSet = "Dokuwiki";  
                 fck.ReplaceTextarea();'
                 );
        }


?>

 
   <form id="dw__editform" method="post" action="<?php echo script()?>"  "accept-charset="<?php echo $lang['encoding']?>">
    <div class="no">
      <input type="hidden" name="id"   value="<?php echo $ID?>" />
      <input type="hidden" name="rev"  value="<?php echo $REV?>" />
      <input type="hidden" name="date" value="<?php echo $DATE?>" />
      <input type="hidden" name="prefix" value="<?php echo formText($PRE)?>" />
      <input type="hidden" name="suffix" value="<?php echo formText($SUF)?>" />
      <input type="hidden" id="fckg_mode_type"  name="mode" value="" />
      <input type="hidden" id="fck_preview_mode"  name="fck_preview_mode" value="nil" />
      <input type="hidden" id="fck_wikitext"    name="fck_wikitext" value="__false__" />     
      <?php
      if(function_exists('formSecurityToken')) {
           formSecurityToken();  
      }
      ?>
    </div>

    <textarea name="wikitext" id="wiki__text" <?php echo $ro?> cols="80" rows="10" class="edit" tabindex="1"><?php echo "\n".$this->xhtml?></textarea>
    
<?php 

$temp=array();
trigger_event('HTML_EDITFORM_INJECTION', $temp);

$DW_EDIT_disabled = '';
$guest_perm = auth_quickaclcheck($_REQUEST['id']);
$guest_group = false;
$guest_user = false;

if(isset($INFO['userinfo'])&& isset($INFO['userinfo']['grps'])) {
   $user_groups = $INFO['userinfo']['grps'];
   if(is_array($user_groups) && $user_groups) {  
      foreach($user_groups as $group) { 
        if (strcasecmp('guest', $group) == 0) {
          $guest_group = true;
          break;
        }
     }
   }
  if($INFO['client'] == 'guest') $guest_user = true; 
}

if(($guest_user || $guest_group) && $guest_perm <= 2) $DW_EDIT_disabled = 'disabled';


$DW_EDIT_hide = $this->dw_edit_displayed(); 

?>

    <div id="wiki__editbar">
      <div id="size__ctl"></div>
      <div id = "fck_size__ctl" style="display: none">
       
       <img src = "<?php echo DOKU_BASE ?>lib/images/smaller.gif"
                    title="edit window smaller"
                    onclick="dwfck_size_ctl('smaller');"   
                    />
       <img src = "<?php echo DOKU_BASE ?>lib/images/larger.gif"
                    title="edit window larger"
                    onclick="dwfck_size_ctl('larger');"   
           />
      </div>
      <?php if($wr){?>
         <div class="editButtons">
            <input type="checkbox" name="fckg" value="fckg" checked="checked" style="display: none"/>
             <input class="button" type="button" 
                   name="do[save]"
                   value="<?php echo $lang['btn_save']?>" 
                   title="<?php echo $lang['btn_save']?> "   
                   <?php echo $DW_EDIT_disabled; ?>
                   onmousedown="parse_wikitext('edbtn__save');"
                  /> 

            <input class="button" id="ebtn__delete" type="submit" 
                   <?php echo $DW_EDIT_disabled; ?>
                   name="do[delete]" value="<?php echo $lang['btn_delete']?>"
                   title="<?php echo $fckg_lang['title_dw_delete'] ?>"
                   style = "font-size: 100%;"
                   onmouseup="draft_delete();"
                   onclick = "return confirm('<?php echo $fckg_lang['confirm_delete']?>');"
            />

            <input type="checkbox" name="fckg" value="fckg" style="display: none"/>
             
             <input class="button"  
                 <?php echo $DW_EDIT_disabled; ?>                 
                 <?php echo $DW_EDIT_hide; ?>
                 style = "font-size: 100%;"
                 onclick ="setDWEditCookie(2, this);parse_wikitext('edbtn__save');this.form.submit();" 
                 type="submit" name="do[save]" value="<?php echo $fckg_lang['btn_dw_edit']?>"  
                 title="<?php echo $fckg_lang['title_dw_edit']?>"
                  />

<?php
 
global $INFO;

  $disabled = 'Disabled';
  $inline = $this->test ? 'inline' : 'none';

  $backup_btn = isset($fckg_lang['dw_btn_backup'])? $fckg_lang['dw_btn_backup'] : $fckg_lang['dw_btn_refresh'];
  $backup_title = isset($fckg_lang['title_dw_backup'])? $fckg_lang['title_dw_backup'] : $fckg_lang['title_dw_refresh'];   
  $using_scayt = ($this->getConf('scayt')) == 'on';
  
?>
            <input class="button" type="submit" 
                 name="do[draftdel]" 
                 value="<?php echo $lang['btn_cancel']?>" 
                 onmouseup="draft_delete();" 
                 style = "font-size: 100%;"
                 title = "<?php echo $fckg_lang['title_dw_cancel']?>"
             />

  
            <input class="button" type="button" value = "<?php echo $fckg_lang['dw_btn_lang']?>"
                  <?php if ($using_scayt) echo 'style = "display:none";'?>
                   title="<?php echo $fckg_lang['title_dw_lang']?>"
                   onclick="aspell_window();"  
                  /> 

            <input class="button" type="button" value = "Test"
                   title="Test"  
                   style = 'display:<?php echo $inline ?>;'
                   onmousedown="parse_wikitext('test');"
                  /> 

 <?php if($this->draft_found) { ?>
             <input class="button"                   
                 onclick ="fckg_get_draft();" 
                 style = "background-color: yellow"
                 id="fckg_draft_btn" 
                 type="button" value="<?php echo $fckg_lang['btn_draft'] ?>"  
                 title="<?php echo $fckg_lang['title_draft'] ?>"
                  />
 <?php } else { ?>

  
             <input class="button" type="button"
                   value="<?php echo $backup_btn ?>"
                   title="<?php echo $backup_title ?>"  
                   onclick="renewLock(true);"  
                  />
 
             <input class="button" type="button"
                   value="<?php echo $fckg_lang['dw_btn_revert']?>"  
                   title="<?php echo $fckg_lang['title_dw_revert']?>"  
                   onclick="revert_to_prev()"  
                  />&nbsp;&nbsp;&nbsp;
              
 <br />

 <?php }  ?>

 <?php if($this->debug) { ?>
         <input class="button" type="button" value = "Debug"
                   title="Debug"                     
                   onclick="HTMLParser_debug();"
                  /> 

            <br />
 <?php } ?>

   <div id = "backup_msg" class="backup_msg" style=" display:none;">
     <table><tr><td class = "backup_msg_td">
      <div id="backup_msg_area" class="backup_msg_area"></div>
     <td>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
     <td align="right">
      <a href="javascript:hide_backup_msg();void(0);" class="backup_msg_close">[ close ]</a>&nbsp;&nbsp;&nbsp;
     </table>
     
 </div>


<input type="checkbox" name="fckg_timer" value="fckg_timer"  id = "fckg_timer"
                      style = 'display:none'
                      onclick="disableDokuWikiLockTimer();"
                      <?php echo $disabled  ?>
                 /><span id='fckg_timer_label'
                    style = 'display:none'>Disable editor time-out messsages </span> 


      <input style="display:none;" class="button" id="edbtn__save" type="submit" name="do[save]" 
                      value="<?php echo $lang['btn_save']?>" 
                      onmouseup="draft_delete();"
                      <?php echo $DW_EDIT_disabled; ?>
                      title="<?php echo $lang['btn_save']?> "  />

            <!-- Not used by fckgLite but required to prevent null error when DW adds events -->
            <input type="button" id='edbtn__preview' style="display: none"/>


 <div id='saved_wiki_html' style = 'display:none;' ></div>
 <div id='fckg_draft_html' style = 'display:none;' >
 <?php echo $this->draft_text; ?>
 </div>

  <script type="text/javascript">
//<![CDATA[
        

        <?php  echo 'var backup_empty = "' . $fckg_lang['backup_empty'] .'";'; ?>

        function aspell_window() {
          var DURL = "<?php echo DOKU_URL; ?>";
          window.open( DURL + "/lib/plugins/fckg/fckeditor/aspell.php?dw_conf_lang=<?php global $conf; echo $conf['lang']?>",
                    "smallwin", "width=600,height=500,scrollbars=yes");
        }

        if(unsetDokuWikiLockTimer) unsetDokuWikiLockTimer();  

        function dwfck_size_ctl(which) {
           var height = parseInt(document.getElementById('wiki__text___Frame').style.height); 
           if(which == 'smaller') {
               height -= 50;
           }
           else {
              height += 50;
           }
           document.getElementById('wiki__text___Frame').style.height = height + 'px'; 
   
        }


var fckgLPluginPatterns = new Array();

<?php
   echo "if(!fckLImmutables) var fckLImmutables = new Array();\n";

   $pos = strpos($_SERVER['HTTP_USER_AGENT'], 'MSIE');
   if($pos === false) {
     echo "var isIE = false;";
   }
   else {
     echo "var isIE = true;";
   }

   echo "var doku_base = '" . DOKU_BASE ."'"; 
     
?>  
          
   var fckg_draft_btn = "<?php echo $fckg_lang['btn_exit_draft'] ?>";
   var fckg_draft_btn_title = "<?php echo $fckg_lang['title_exit_draft']?>";
   function fckg_get_draft() {
      var dom = GetE('fckg_draft_html');
      var draft = dom.innerHTML;
      var dw_text = oDokuWiki_FCKEditorInstance.GetData( true );    
      oInst = oDokuWiki_FCKEditorInstance.get_FCK();
      oInst =oInst.EditorDocument.body;
      oInst.innerHTML = draft;
      dom.innerHTML = dw_text;
      var btn = GetE('fckg_draft_btn');
      var tmp = btn.value;  
      btn.value = fckg_draft_btn;
      fckg_draft_btn = tmp;
      tmp = fckg_draft_btn_title;
      btn.title = fckg_draft_btn_title;
      fckg_draft_btn_title = tmp;
   }


   function safe_convert(value) {            

     if(oDokuWiki_FCKEditorInstance.dwiki_fnencode && oDokuWiki_FCKEditorInstance.dwiki_fnencode == 'safe') {
      <?php
       global $updateVersion;
       if(!isset($updateVersion)) $updateVersion = 0;
       echo "updateVersion=$updateVersion;";
       $list = plugin_list('action');       
       $safe_converted = false;
       if(in_array( 'safefnrecode' , $list)) {
          $safe_converted = true;          
       }
       
     ?>

 		if(value.match(/%25/ && value.match(/%25[a-z0-9]/))) {
                          value = value.replace(/%25/g,"%");
                          <?php                         
                          if($updateVersion > 30 || $safe_converted) {
                            echo 'value = value.replace(/%5D/g,"]");';
                          }
                          ?>

                          value =  dwikiUTF8_decodeFN(value,'safe');
                       }
        }
        return value; 

     }
	 
RegExp.escape = function(str)
{
    var specials = new RegExp("[.*+?|()\\[\\]{}\\\\]", "g"); // .*+?|()[]{}\
    return str.replace(specials, "\\$&");
}

var HTMLParser_DEBUG = "";
function parse_wikitext(id) {
    if(id) {
       var dom =  GetE(id);
      dom.click();
      return true;
    }
}

 //]]>

  </script>


         </div>
<?php } ?>

      <?php if($wr){ ?>
        <div class="summary">
           <label for="edit__summary" class="nowrap"><?php echo $lang['summary']?>:</label>
           <input type="text" class="edit" name="summary" id="edit__summary" size="50" value="<?php echo formText($SUM)?>" tabindex="2" />
           <label class="nowrap" for="minoredit"><input type="checkbox" id="minoredit" name="minor" value="1" tabindex="3" /> <span>Minor Changes</span></label>
        </div>
      <?php }?>
  </div>
  </form>

  <!-- draft messages from DW -->
  <div id="draft__status"></div>
  
<?php
    }

  function write_debug($what) {
     return;
     $handle = fopen("edit_php.txt", "a");
     if(is_array($what)) $what = print_r($what,true);
     fwrite($handle,"$what\n");
     fclose($handle);
  }
 /**
  *  @author Myron Turner <turnermm02@shaw.ca>
  *  Converts FCK extended syntax to native DokuWiki syntax
 */
  function fck_convert_text(&$event) {
  }
  

 function big_file() {   
 }

/**
 * get regular expressions for installed plugins 
 * @author     Myron Turner <turnermm02@shaw.ca>
 * return string of regexes suitable for PCRE matching
*/
 function get_plugins() {
 global $DOKU_PLUGINS;

 $list = plugin_list('syntax');
 $data =  $DOKU_PLUGINS['syntax'][$list[0]]->Lexer->_regexes['base']->_labels; 
 $patterns = $DOKU_PLUGINS['syntax'][$list[0]]->Lexer->_regexes['base']->_patterns;
 $labels = array();
 $regex = '~~NOCACHE~~';
 $regex .= "|\{\{rss>http:\/\/.*?\}\}";

 $exclusions = $this->getConf('xcl_plugins');
 $exclusions = trim($exclusions, " ,");
 $exclusions = explode  (',', $exclusions);
 $exclusions[] = 'fckg_font';
 $list = array_diff($list,$exclusions);

 foreach($list as $plugin) {
   if(preg_match('/fckg_dwplugin/',$plugin)) continue;
   $plugin = 'plugin_' . $plugin;

   $indices = array_keys($data, $plugin);
   if(empty($indices)) {
       $plugin = '_' . $plugin;

      $indices = array_keys($data, $plugin);
   }

   if(!empty($indices)) {
       foreach($indices as $index) {
          $labels[] = "$index: " . $patterns[$index];         
          $pattern = $patterns[$index];       
          $pattern = preg_replace('/^\(\^/',"(",$pattern); 
          $regex .= "|$pattern";       
       }
    }
    
 }
 $regex = ltrim($regex, '|'); 

 $regex_xcl = array();
 foreach($exclusions as $plugin) {
   if(preg_match('/fckg_dwplugin/',$plugin)) continue;
   $plugin = 'plugin_' . $plugin;

   $indices = array_keys($data, $plugin);
   if(empty($indices)) {
       $plugin = '_' . $plugin;
      $indices = array_keys($data, $plugin);
   }

   if(!empty($indices)) {
       foreach($indices as $index) { 
            $pos = strpos($patterns[$index],'<');
            if($pos !== false) {
               $pattern = str_replace('<', '\/\/<\/\/', $patterns[$index]);
               $pattern = str_replace('?=',"",$pattern);
               $regex_xcl[] = $pattern; 
            }
          }
       }
    }

 return array('plugins'=> $regex, 'xcl'=> $regex_xcl);
 //return $regex; 

 }

} //end of action class


?>
