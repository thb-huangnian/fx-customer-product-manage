<?php
// +----------------------------------------------------------------------
// | CoreThink [ Simple Efficient Excellent ]
// +----------------------------------------------------------------------
// | Copyright (c) 2014 http://www.corethink.cn All rights reserved.
// +----------------------------------------------------------------------
// | Author: ijry <ijry@qq.com> <http://www.corethink.cn>
// +----------------------------------------------------------------------
namespace Admin\Controller;
use Admin\Model\AuthGroupModel;
use Think\Page;
/**
 * 后台内容控制器
 * @author huajie <banhuajie@163.com>
 */
class ArticleController extends AdminController {
    /* 保存允许访问的公共方法 */
    static protected $allow = array( 'draftbox','mydocument');

    private $cate_id        =   null; //文档分类id

    /**
     * 检测需要动态判断的文档类目有关的权限
     *
     * @return boolean|null
     *      返回true则表示当前访问有权限
     *      返回false则表示当前访问无权限
     *      返回null，则会进入checkRule根据节点授权判断权限
     *
     * @author 朱亚杰  <xcoolcc@gmail.com>
     */
    protected function checkDynamic(){
        $cates = AuthGroupModel::getAuthCategories(UID);
        switch(strtolower(ACTION_NAME)){
            case 'index':   //文档列表
            case 'add':   // 新增
                $cate_id =  I('cate_id');
                break;
            case 'edit':    //编辑
            case 'update':  //更新
                $doc_id  =  I('id');
                $cate_id =  M('Document')->where(array('id'=>$doc_id))->getField('category_id');
                break;
            case 'setstatus': //更改状态
            case 'permit':    //回收站
                $doc_id  =  (array)I('ids');
                $cate_id =  M('Document')->where(array('id'=>array('in',$doc_id)))->getField('category_id',true);
                $cate_id =  array_unique($cate_id);
                break;
        }
        if(!$cate_id){
            return null;//不明
        }elseif( !is_array($cate_id) && in_array($cate_id,$cates) ) {
            return true;//有权限
        }elseif( is_array($cate_id) && $cate_id==array_intersect($cate_id,$cates) ){
            return true;//有权限
        }else{
            return false;//无权限
        }
    }

    /**
     * 显示左边菜单，进行权限控制
     * @author huajie <banhuajie@163.com>
     */
    protected function getMenu(){
        //获取动态分类
        $cate_auth  =   AuthGroupModel::getAuthCategories(UID); //获取当前用户所有的内容权限节点
        $cate_auth  =   $cate_auth == null ? array() : $cate_auth;
        $cate       =   M('Category')->where(array('status'=>1))->field('id,title,pid,allow_publish')->order('pid,sort')->select();

        //没有权限的分类则不显示
        if(!IS_ROOT){
            foreach ($cate as $key=>$value){
                if(!in_array($value['id'], $cate_auth)){
                    unset($cate[$key]);
                }
            }
        }
        $cate = $this->tree($cate);

        //获取分类id
        $cate_id        =   I('param.cate_id');
        $this->cate_id  =   $cate_id;

        $this->assign('category_tree',$cate);
        $this->assign('cate_id',$this->cate_id);
        $this->assign('rightNav',get_parent_category($cate_id)); // 获取面包屑信息
        $this->assign('show_recycle',IS_ROOT || $this->checkRule('Admin/article/recycle')); //获取回收站权限 
        $this->assign('show_draftbox',C('OPEN_DRAFTBOX')); // 获取草稿箱权限
        $this->assign('show_examine',IS_ROOT || $this->checkRule('Admin/article/examine')); // 获取审核列表权限
    }

    /**
     * 分类文档列表页
     * @param integer $cate_id 分类id
     * @param integer $model_id 模型id
     * @param integer $position 推荐标志
     * @param integer $group_id 分组id
     */
    public function index($cate_id = null, $model_id = null, $position = null, $group_id=null){
        //获取分类树及面包屑导航信息
        $this->getMenu();

        if($cate_id===null){
            $cate_id = $this->cate_id;
        }
        if(!empty($cate_id)){
            $pid = I('pid',0);
            // 获取列表绑定的模型
            if ($pid == 0) {
                $models     =   get_category($cate_id, 'model');
                // 获取分组定义
                $groups        =    get_category($cate_id, 'groups');
                if($groups){
                    $groups    =    parse_field_attr($groups);
                }
            }else{ // 子文档列表
                $models   =   get_category($cate_id, 'model_sub');
            }
            if(is_null($model_id) && !is_numeric($models)){
                // 绑定多个模型 取基础模型的列表定义
                $model = M('Model')->getByName('document');
            }else{
                $model_id   =   $model_id ? : $models;
                //获取模型信息
                $model = M('Model')->getById($model_id);
                if (empty($model['list_grid'])) {
                    $model['list_grid'] = M('Model')->getFieldByName('document','list_grid');
                }
            }
            $this->assign('model', explode(',', $models));
        }else{
            // 获取基础模型信息
            $model = M('Model')->getByName('document');
            $model_id   =   null;
            $cate_id    =   0;
            $this->assign('model', null);
        }

        //解析列表规则
        $fields = array();
        $grids  = preg_split('/[;\r\n]+/s', trim($model['list_grid']));
        foreach ($grids as &$value) {
            // 字段:标题:链接
            $val      = explode(':', $value);
            // 支持多个字段显示
            $field   = explode(',', $val[0]);
            $value    = array('field' => $field, 'title' => $val[1]);
            if(isset($val[2])){
                // 链接信息
                $value['href']  =   $val[2];
                // 搜索链接信息中的字段信息
                preg_replace_callback('/\[([a-z_]+)\]/', function($match) use(&$fields){$fields[]=$match[1];}, $value['href']);
            }
            if(strpos($val[1],'|')){
                // 显示格式定义
                list($value['title'],$value['format'])    =   explode('|',$val[1]);
            }
            foreach($field as $val){
                $array  =   explode('|',$val);
                $fields[] = $array[0];
            }
        }

        // 文档模型列表始终要获取的数据字段 用于其他用途
        $fields[] = 'category_id';
        $fields[] = 'model_id';
        $fields[] = 'pid';
        // 过滤重复字段信息
        $fields =   array_unique($fields);
        // 列表查询
        $list   =   $this->getDocumentList($cate_id,$model_id,$position,$fields,$group_id);
        // 列表显示处理
        $list   =   $this->parseDocumentList($list,$model_id);

        $this->assign('model_id',$model_id);
        $this->assign('group_id',$group_id);
        $this->assign('position',$position);
        $this->assign('groups', $groups);
        $this->assign('list',$list);
        $this->assign('list_grids',$grids);
        $this->assign('model_list',$model);
        // 记录当前列表页的cookie
        Cookie('__forward__',$_SERVER['REQUEST_URI']);
        $this->display();
    }

    /* 递归解析分类 */
    public function tree($list,$pid=0,$level=0,$html='—'){
        static $tree = array();
        foreach($list as $v){
            if($v['pid'] == $pid){
                $v['sort'] = $level;
                $v['title'] = str_repeat($html,$level).$v['title'];
                $tree[] = $v;
                $this->tree($list,$v['id'],$level+1);
            }
        }
        return $tree;
    }

    /**
     * 默认文档列表方法
     * @param integer $cate_id 分类id
     * @param integer $model_id 模型id
     * @param integer $position 推荐标志
     * @param mixed $field 字段列表
     * @param integer $group_id 分组id
     */
    protected function getDocumentList($cate_id=0,$model_id=null,$position=null,$field=true,$group_id=null){
        /* 查询条件初始化 */
        $map = array();
        if(isset($_GET['title'])){
            $map['title']  = array('like', '%'.(string)I('title').'%');
        }
        if(isset($_GET['status'])){
            $map['status'] = I('status');
            $status = $map['status'];
        }else{
            $status = null;
            $map['status'] = array('in', '0,1,2');
        }
        if ( isset($_GET['time-start']) ) {
            $map['update_time'][] = array('egt',strtotime(I('time-start')));
        }
        if ( isset($_GET['time-end']) ) {
            $map['update_time'][] = array('elt',24*60*60 + strtotime(I('time-end')));
        }
        if ( isset($_GET['nickname']) ) {
            $map['uid'] = M('Member')->where(array('nickname'=>I('nickname')))->getField('uid');
        }

        // 构建列表数据
        $Document = M('Document');
        if(0 == $cate_id){
            $order = "DOCUMENT.id DESC";
        }else{
            $map['category_id'] = $cate_id;
            $order = "level DESC,DOCUMENT.id DESC";
        }
        $map['pid']         =   I('pid',0);
        if($map['pid']){ // 子文档列表忽略分类
            unset($map['category_id']);
        }
        $Document->alias('DOCUMENT');
        if(!is_null($model_id)){
            $map['model_id']    =   $model_id;
            if(is_array($field) && array_diff($Document->getDbFields(),$field)){
                $modelName  =   M('Model')->getFieldById($model_id,'name');
                $Document->join('__DOCUMENT_'.strtoupper($modelName).'__ '.$modelName.' ON DOCUMENT.id='.$modelName.'.id');
                $key = array_search('id',$field);
                if(false  !== $key){
                    unset($field[$key]);
                    $field[] = 'DOCUMENT.id';
                }
            }
        }
        if(!is_null($position)){
            $map[] = "position & {$position} = {$position}";
        }
        if(!is_null($group_id)){
            $map['group_id']    =    $group_id;
        }
        $list = $this->lists($Document,$map,$order,$field);

        if($map['pid']){
            // 获取上级文档
            $article    =   $Document->field('id,title,type')->find($map['pid']);
            $this->assign('article',$article);
        }
        //检查该分类是否允许发布内容
        $allow_publish  =   get_category($cate_id, 'allow_publish');

        $this->assign('status', $status);
        $this->assign('allow',  $allow_publish);
        $this->assign('pid',    $map['pid']);

        $this->meta_title = '文档列表';
        return $list;
    }

    /**
     * 设置一条或者多条数据的状态
     * @author huajie <banhuajie@163.com>
     */
    public function setStatus($model='Document'){
        return parent::setStatus('Document');
    }

    /**
     * 文档新增页面初始化
     * @author huajie <banhuajie@163.com>
     */
    public function add(){
        //获取分类树及面包屑导航信息
        $this->getMenu();

        $cate_id    =   I('get.cate_id',0);
        $model_id   =   I('get.model_id',0);
        $group_id    =    I('get.group_id','');

        empty($cate_id) && $this->error('参数不能为空！');
        empty($model_id) && $this->error('该分类未绑定模型！');

        //检查该分类是否允许发布
        $allow_publish = check_category($cate_id);
        !$allow_publish && $this->error('该分类不允许发布内容！');

        // 获取当前的模型信息
        $model    =   get_document_model($model_id);

        //处理结果
        $info['pid']            =   $_GET['pid']?$_GET['pid']:0;
        $info['model_id']       =   $model_id;
        $info['category_id']    =   $cate_id;
        $info['group_id']       =   $group_id;
        if($info['pid']){
            // 获取上级文档
            $article            =   M('Document')->field('id,title,type')->find($info['pid']);
            $this->assign('article',$article);
        }

        //获取表单字段排序
        $fields = get_model_attribute($model['id']);

        $product_cate = M('product_category')->where(array('status' => 1))->order('company, createtime desc')->select();

        $this->assign('info',       $info);
        $this->assign('fields',     $fields);
        $this->assign('type_list',  get_type_bycate($cate_id));
        $this->assign('model',      $model);
        $this->assign('cate_id', $cate_id);
        $this->assign('productcate', $product_cate);
        $this->meta_title = '新增'.$model['title'];
        $this->display();
    }

    /**
     * 文档编辑页面初始化
     * @author huajie <banhuajie@163.com>
     */
    public function edit(){
        //获取分类树及面包屑导航信息
        $this->getMenu();

        $id     =   I('get.id','');
        if(empty($id)){
            $this->error('参数不能为空！');
        }

        // 获取详细数据
        $Document = D('Document');
        $data = $Document->detail($id);
        if(!$data){
            $this->error($Document->getError());
        }

        if($data['pid']){
            // 获取上级文档
            $article        =   $Document->field('id,title,type')->find($data['pid']);
            $this->assign('article',$article);
        }
        // 获取当前的模型信息
        $model    =   get_document_model($data['model_id']);

        $this->assign('data', $data);
        $this->assign('model_id', $data['model_id']);
        $this->assign('model',      $model);

        //获取表单字段排序
        $fields = get_model_attribute($model['id']);
        $this->assign('fields',     $fields);

        $product_cate = M('product_category')->where(array('status' => 1))->order('company, createtime desc')->select();
        $this->assign('productcate', $product_cate);

        //获取当前分类的文档类型
        $this->assign('type_list', get_type_bycate($data['category_id']));

        $this->meta_title   =   '编辑文档';
        $this->display();
    }

    /**
     * 更新一条数据
     * @author huajie <banhuajie@163.com>
     */
    public function update(){
        //标签信息更新
        $tags = D('Tags');
        if($_POST['id']){
            $orignal_tags = D('Document')->getFieldById($_POST['id'], 'tags');
            if($orignal_tags && !$tags->setDecByTags($orignal_tags)){ // 更新时候先将原有标签统计减去一
                $this->error("标签Count更新错误");
            }
        }
        if($_POST["tags"]){
            $tags->updateTags(explode(',', $_POST["tags"])); // 更新标签信息
        }
        //文档信息更新
        $document   =   D('Document');
        $res = $document->update();
        if(!$res){
            $this->error($document->getError());
        }else{
            $this->success($res['id']?'更新成功':'新增成功', Cookie('__forward__'));
        }
    }

    /**
     * 待审核列表
     */
    public function examine(){
        $map['status']  =   2;
        if ( !IS_ROOT ) {
            $cate_auth  =   AuthGroupModel::getAuthCategories(UID);
            if($cate_auth){
                $map['category_id']    =   array('IN',$cate_auth);
            }else{
                $map['category_id']    =   -1;
            }
        }
        $list = $this->lists(M('Document'),$map,'update_time desc');
        //处理列表数据
        if(is_array($list)){
            foreach ($list as $k=>&$v){
                $v['username']      =   get_user_info($v['uid'], 'nickname');
            }
        }

        $this->assign('list', $list);
        $this->meta_title       =   '待审核';
        $this->display();
    }

    /**
     * 回收站列表
     * @author huajie <banhuajie@163.com>
     */
    public function recycle(){
        $map['status']  =   -1;
        if ( !IS_ROOT ) {
            $cate_auth  =   AuthGroupModel::getAuthCategories(UID);
            if($cate_auth){
                $map['category_id']    =   array('IN',$cate_auth);
            }else{
                $map['category_id']    =   -1;
            }
        }
        $list = $this->lists(M('Document'),$map,'update_time desc');

        //处理列表数据
        if(is_array($list)){
            foreach ($list as $k=>&$v){
                $v['username']      =   get_user_info($v['uid'], 'nickname');
            }
        }

        $this->assign('list', $list);
        $this->meta_title       =   '回收站';
        $this->display();
    }

    /**
     * 写文章时自动保存至草稿箱
     * @author huajie <banhuajie@163.com>
     */
    public function autoSave(){
        $res = D('Document')->autoSave();
        if($res !== false){
            $return['data']     =   $res;
            $return['info']     =   '保存草稿成功';
            $return['status']   =   1;
            $this->ajaxReturn($return);
        }else{
            $this->error('保存草稿失败：'.D('Document')->getError());
        }
    }

    /**
     * 草稿箱
     * @author huajie <banhuajie@163.com>
     */
    public function draftBox(){
        $Document   =   D('Document');
        $map        =   array('status'=>3,'uid'=>UID);
        $list       =   $this->lists($Document,$map);

        $this->assign('list', $list);
        $this->meta_title = '草稿箱';
        $this->display();
    }

    /**
     * 我的文档
     * @author huajie <banhuajie@163.com>
     */
    public function mydocument($status = null, $title = null){
        $Document   =   D('Document');
        /* 查询条件初始化 */
        $map['uid'] = UID;
        $map['display'] = 1;
        if(isset($title)){
            $map['title']   =   array('like', '%'.$title.'%');
        }
        if(isset($status)){
            $map['status']  =   $status;
        }else{
            $map['status']  =   array('in', '0,1,2');
        }
        if ( isset($_GET['time-start']) ) {
            $map['update_time'][] = array('egt',strtotime(I('time-start')));
        }
        if ( isset($_GET['time-end']) ) {
            $map['update_time'][] = array('elt',24*60*60 + strtotime(I('time-end')));
        }
        //只查询pid为0的文章
        $map['pid'] = 0;
        $list = $this->lists($Document,$map,'update_time desc');
        $list = $this->parseDocumentList($list,1);

        // 记录当前列表页的cookie
        Cookie('__forward__',$_SERVER['REQUEST_URI']);
        $this->assign('status', $status);
        $this->assign('list', $list);
        $this->meta_title = '我的文档';
        $this->display();
    }

    /**
     * 还原被删除的数据
     * @author huajie <banhuajie@163.com>
     */
    public function permit(){
        /*参数过滤*/
        $ids = I('param.ids');
        if(empty($ids)){
            $this->error('请选择要操作的数据');
        }

        /*拼接参数并修改状态*/
        $Model  =   'Document';
        $map    =   array();
        if(is_array($ids)){
            $map['id'] = array('in', $ids);
        }elseif (is_numeric($ids)){
            $map['id'] = $ids;
        }
        $this->restore($Model,$map);
    }

    /**
     * 清空回收站
     * @author huajie <banhuajie@163.com>
     */
    public function clear(){
        $res = D('Document')->remove();
        if($res !== false){
            $this->success('清空回收站成功！');
        }else{
            $this->error('清空回收站失败！');
        }
    }

    /**
     * 移动文档
     * @author huajie <banhuajie@163.com>
     */
    public function move() {
        if(empty($_POST['ids'])) {
            $this->error('请选择要移动的文档！');
        }
        session('moveArticle', $_POST['ids']);
        session('copyArticle', null);
        $this->success('请选择要移动到的分类，然后点击粘贴！');
    }

    /**
     * 拷贝文档
     * @author huajie <banhuajie@163.com>
     */
    public function copy() {
        if(empty($_POST['ids'])) {
            $this->error('请选择要复制的文档！');
        }
        session('copyArticle', $_POST['ids']);
        session('moveArticle', null);
        $this->success('请选择要复制到的分类，然后点击粘贴！');
    }

    /**
     * 粘贴文档
     * @author huajie <banhuajie@163.com>
     */
    public function paste() {
        $moveList = session('moveArticle');
        $copyList = session('copyArticle');
        if(empty($moveList) && empty($copyList)) {
            $this->error('没有选择文档！');
        }
        if(!isset($_POST['cate_id'])) {
            $this->error('请选择要粘贴到的分类！');
        }
        $cate_id = I('post.cate_id');   //当前分类
        $pid = I('post.pid', 0);        //当前父类数据id

        //检查所选择的数据是否符合粘贴要求
        $check = $this->checkPaste(empty($moveList) ? $copyList : $moveList, $cate_id, $pid);
        if(!$check['status']){
            $this->error($check['info']);
        }

        if(!empty($moveList)) {// 移动    TODO:检查name重复
            foreach ($moveList as $key=>$value){
                $Model              =   M('Document');
                $map['id']          =   $value;
                $data['category_id']=   $cate_id;
                $data['pid']        =   $pid;
                //获取root
                if($pid == 0){
                    $data['root'] = 0;
                }else{
                    $p_root = $Model->getFieldById($pid, 'root');
                    $data['root'] = $p_root == 0 ? $pid : $p_root;
                }
                $res = $Model->where($map)->save($data);
            }
            session('moveArticle', null);
            if(false !== $res){
                $this->success('文档移动成功！');
            }else{
                $this->error('文档移动失败！');
            }
        }elseif(!empty($copyList)){ // 复制
            foreach ($copyList as $key=>$value){
                $Model  =   M('Document');
                $data   =   $Model->find($value);
                unset($data['id']);
                unset($data['name']);
                $data['category_id']    =   $cate_id;
                $data['pid']            =   $pid;
                $data['create_time']    =   NOW_TIME;
                $data['update_time']    =   NOW_TIME;
                //获取root
                if($pid == 0){
                    $data['root'] = 0;
                }else{
                    $p_root = $Model->getFieldById($pid, 'root');
                    $data['root'] = $p_root == 0 ? $pid : $p_root;
                }

                $result   =  $Model->add($data);
                if($result){
                    $logic      =   logic($data['model_id']);
                    $data       =   $logic->detail($value); //获取指定ID的扩展数据
                    $data['id'] =   $result;
                    $res        =   $logic->add($data);
                }
            }
            session('copyArticle', null);
            if($res){
                $this->success('文档复制成功！');
            }else{
                $this->error('文档复制失败！');
            }
        }
    }

    /**
     * 检查数据是否符合粘贴的要求
     * @author huajie <banhuajie@163.com>
     */
    protected function checkPaste($list, $cate_id, $pid){
        $return    =  array('status'=>1);
        $Document  =  D('Document');

        // 检查支持的文档模型
        if($pid){
            $modelList =   M('Category')->getFieldById($cate_id,'model_sub');   // 当前分类支持的文档模型
        }else{
            $modelList =   M('Category')->getFieldById($cate_id,'model');   // 当前分类支持的文档模型
        }

        foreach ($list as $key => $value){
            //不能将自己粘贴为自己的子内容
            if($value == $pid){
                $return['status'] = 0;
                $return['info'] = '不能将编号为 '.$value.' 的数据粘贴为他的子内容！';
                return $return;
            }
            // 移动文档的所属文档模型
            $modelType  =   $Document->getFieldById($value,'model_id');
            if(!in_array($modelType,explode(',',$modelList))) {
                $return['status'] = 0;
                $return['info'] = '当前分类的文档模型不支持编号为 '.$value.' 的数据！';
                return $return;
            }
        }

        // 检查支持的文档类型和层级规则
        $typeList =   M('Category')->getFieldById($cate_id,'type'); // 当前分类支持的文档模型
        foreach ($list as $key=>$value){
            // 移动文档的所属文档模型
            $modelType  =   $Document->getFieldById($value,'type');
            if(!in_array($modelType,explode(',',$typeList))) {
                $return['status'] = 0;
                $return['info'] = '当前分类的文档类型不支持编号为 '.$value.' 的数据！';
                return $return;
            }
            $res = $Document->checkDocumentType($modelType, $pid);
            if(!$res['status']){
                $return['status'] = 0;
                $return['info'] = $res['info'].'。错误数据编号：'.$value;
                return $return;
            }
        }

        return $return;
    }

    /**
     * 文档排序
     * @author huajie <banhuajie@163.com>
     */
    public function sort(){
        if(IS_GET){
            $ids        =   I('get.ids');
            $cate_id    =   I('get.cate_id');
            $pid        =   I('get.pid');

            //获取排序的数据
            $map['status'] = array('gt',-1);
            if(!empty($ids)){
                $map['id'] = array('in',$ids);
            }else{
                if($cate_id !== ''){
                    $map['category_id'] = $cate_id;
                }
                if($pid !== ''){
                    $map['pid'] = $pid;
                }
            }
            $list = M('Document')->where($map)->field('id,title')->order('level DESC,id DESC')->select();

            $this->assign('list', $list);
            $this->meta_title = '文档排序';
            $this->display();
        }elseif (IS_POST){
            $ids = I('post.ids');
            $ids = array_reverse(explode(',', $ids));
            foreach ($ids as $key=>$value){
                $res = M('Document')->where(array('id'=>$value))->setField('level', $key+1);
            }
            if($res !== false){
                $this->success('排序成功！');
            }else{
                $this->error('排序失败！');
            }
        }else{
            $this->error('非法请求！');
        }
    }

    public function productcategory(){

        $map = array();
        $map['status'] = 1;
        
        $product_cate = M('product_category')->where($map)->order('company, createtime desc')->select();
        $this->assign('cates', $product_cate);
        $this->assign('_total', count($product_cate));
        $this->meta_title = '产品分类列表';

        $this->display();
        
    }

    public function proedit(){
        $data['type'] = trim(I('type')) ? trim(I('type')): trim(I('request.type'));
        $data['name'] = trim(I('name'));
        $data['description'] = trim(I('description'));
        $data['company'] = trim(I('company'));
        $data['coverid'] = trim(I('cover_id'));
        $id = intval(I('id'));
        $ids    =   I('request.ids');

        if ($data['type'] == 'deletes') {
            
            if(empty($ids)){
                $this->error('请选择要操作的数据');
            }
                
            $map['id'] = array('in',$ids);
            M('product_category')->where($map)->delete();

            $this->success('删除成功', U('Article/productcategory'));
        }

        if (IS_POST) {
            if (empty($data['name'])) {
                $this->error('名称不能为空');
            }elseif (empty($data['description'])) {
                $this->error('描述不能为空');
            }elseif (empty($data['company'])) {
                $this->error('公司名不能为空');
            }elseif (empty($data['coverid'])) {
                $this->error('分类图片不能为空');
            }

            if ($data['type'] == 'add') {
                $data['createtime'] = time();

                M('product_category')->add($data);

                $this->success('新增成功', U('Article/productcategory'));
            }elseif ($data['type'] == 'edit') {
                $data['updatetime'] = time();
                M('product_category')->where(array('id' => $id))->save($data);

                $this->success('更新成功');
            }
            
        }else{
            if ($data['type'] == 'delete') {
                M('product_category')->where(array('id' => $id))->delete();

                $this->success('删除成功', U('Article/productcategory'));
            }else{
                if (!empty($id)) {
                    $map = array();
                    $map['status'] = 1;
                    $map['id'] = $id;

                    $result = M('product_category')->where($map)->find();
                    if (empty($result)) {
                        $this->error('非法请求！');
                    }

                    $this->assign('typename', '编辑产品分类');
                    $this->assign('is_edit', 1);
                    $this->assign('data', $result);
                    $this->meta_title = '编辑产品分类';
                }else{
                    $this->assign('typename', '新增产品分类');
                    $this->meta_title = '新增产品分类';
                }

                $this->display();
            }
        }
        
    }
}
