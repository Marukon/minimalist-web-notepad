<?php

// Path to the directory to save the notes in, without trailing slash.
// Should be outside the document root, if possible.
$save_path = '_tmp';

// Disable caching.
header('Cache-Control: no-store');

// If no note name is provided, or if the name is too long, or if it contains invalid characters.
if (!isset($_GET['note']) || strlen($_GET['note']) > 64 || !preg_match('/^[a-zA-Z0-9_-]+$/', $_GET['note'])) {
    // Generate a name with 5 random unambiguous characters. Redirect to it.
    header("Location: " . substr(str_shuffle('234579abcdefghjkmnpqrstwxyz'), -5));
    die;
}

$path = $save_path . '/' . $_GET['note'];

// 处理版本控制和冲突检测
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $text = isset($_POST['text']) ? $_POST['text'] : file_get_contents("php://input");
    $clientVersion = isset($_POST['version']) ? intval($_POST['version']) : 0;
    $userId = isset($_POST['userId']) ? $_POST['userId'] : '';

    // 读取服务器当前内容和版本
    $serverData = file_exists($path) ? file_get_contents($path) : '';
    $currentVersion = file_exists($path . '.version') ? intval(file_get_contents($path . '.version')) : 0;

    // 冲突检测
    if ($clientVersion < $currentVersion) {
        header('HTTP/1.1 409 Conflict');
        echo json_encode([
            'serverContent' => $serverData,
            'serverVersion' => $currentVersion
        ]);
        die;
    }

    // 更新文件内容
    file_put_contents($path, $text);
    
    // 更新版本号
    $newVersion = $currentVersion + 1;
    file_put_contents($path . '.version', $newVersion);

    // 如果提供的输入为空，删除文件
    if (!strlen($text)) {
        unlink($path);
        unlink($path . '.version');
    }

    echo json_encode([
        'version' => $newVersion,
        'userId' => $userId
    ]);
    die;
}

// Print raw file when explicitly requested, or if the client is curl or wget.
if (isset($_GET['raw']) || strpos($_SERVER['HTTP_USER_AGENT'], 'curl') === 0 || strpos($_SERVER['HTTP_USER_AGENT'], 'Wget') === 0) {
    if (is_file($path)) {
        header('Content-type: application/json');
        $version = file_exists($path . '.version') ? file_get_contents($path . '.version') : 0;
        echo json_encode([
            'content' => file_get_contents($path),
            'version' => $version
        ]);
    } else {
        header('HTTP/1.0 404 Not Found');
    }
    die;
}

?><!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php print $_GET['note']; ?></title>
<link rel="icon" href="favicon.ico" sizes="any">
<link rel="icon" href="favicon.svg" type="image/svg+xml">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
<style>
body {
    margin: 0;
    background: #ebeef1;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, Cantarell, "Open Sans", "Helvetica Neue", sans-serif;
}
.container {
    position: absolute;
    top: 70px;
    right: 20px;
    bottom: 20px;
    left: 20px;
}
#content, #preview {
    margin: 0;
    padding: 20px;
    overflow-y: auto;
    width: 100%;
    height: 100%;
    box-sizing: border-box;
    border: 1px solid #ddd;
    outline: none;
    line-height: 1.6;
    font-size: 16px;
}
#content {
    resize: none;
}
#preview {
    white-space: pre-wrap;
    word-wrap: break-word;
    background: white;
    display: none;
}
#preview strong {
    font-size: 1.5em;
}
#printable {
    display: none;
}
#status {
    position: fixed;
    bottom: 20px;
    right: 20px;
    padding: 12px 24px;
    border-radius: 4px;
    color: white;
    background: #4CAF50;
    box-shadow: 0 2px 5px rgba(0,0,0,0.2);
    opacity: 0;
    transition: opacity 0.3s;
    z-index: 1000;
}
#toolbar {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(100px, 1fr));
    gap: 10px;
    position: fixed;
    top: 10px;
    left: 20px;
    right: 20px;
    align-items: stretch;
    z-index: 10;
    background: #ebeef1;
    padding: 10px;
    border-radius: 4px;
}
#toolbar button {
    display: flex;
    align-items: center;
    justify-content: center;
    text-align: center;
    padding: 10px;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-size: 14px;
    transition: all 0.3s ease;
    gap: 8px;
    font-weight: 500;
}
#toolbar button i {
    margin-right: 5px;
}
#previewBtn { background: #4CAF50; color: white; }
#boldBtn { background: #FF9800; color: white; }
#copyBtn { background: #2196F3; color: white; }
#refreshBtn { background: #9C27B0; color: white; }
#downloadBtn { background: #607D8B; color: white; }
#undoBtn { background: #F44336; color: white; }
#redoBtn { background: #8BC34A; color: white; }
#saveBtn { background: #3F51B5; color: white; }

#toolbar button:hover {
    opacity: 0.9;
    transform: translateY(-2px);
    box-shadow: 0 2px 5px rgba(0,0,0,0.2);
}

@media (max-width: 600px) {
    #toolbar {
        grid-template-columns: repeat(3, 1fr);
        gap: 5px;
        top: 0;
        left: 0;
        right: 0;
        padding: 5px;
    }
    .container {
        top: 80px;
    }
    #toolbar button {
        font-size: 12px;
        padding: 8px;
        gap: 4px;
    }
    #toolbar button i {
        margin-right: 5px;
    }
    #downloadBtn {
        display: none !important;
    }
}

@media (prefers-color-scheme: dark) {
    body { background: #333b4d; }
    #content, #preview {
        background: #24262b;
        color: #fff;
        border-color: #495265;
    }
    #toolbar {
        background: #333b4d;
    }
}

@media print {
    .container { display: none; }
    #printable {
        display: block;
        white-space: pre-wrap;
        word-break: break-word;
    }
    #status, #toolbar { display: none; }
}
</style>
</head>
<body>
<div id="toolbar">
    <button id="previewBtn"><i class="fas fa-eye"></i>预览</button>
    <button id="boldBtn"><i class="fas fa-bold"></i>加粗选中</button>
    <button id="copyBtn"><i class="fas fa-copy"></i>复制全部</button>
    <button id="refreshBtn"><i class="fas fa-sync"></i>刷新内容</button>
    <button id="saveBtn"><i class="fas fa-save"></i>手动保存</button>
    <button id="undoBtn"><i class="fas fa-undo"></i>撤销</button>
    <button id="redoBtn"><i class="fas fa-redo"></i>重做</button>
    <button id="downloadBtn"><i class="fas fa-download"></i>下载文件</button>
</div>
<div class="container">
<textarea id="content"><?php
if (is_file($path)) {
    print htmlspecialchars(file_get_contents($path), ENT_QUOTES, 'UTF-8');
}
?></textarea>
<div id="preview"></div>
</div>
<pre id="printable"></pre>
<div id="status"></div>
<script>
class UndoManager {
    constructor(maxHistory = 20) {
        this.history = [];
        this.currentIndex = -1;
        this.maxHistory = maxHistory;
    }

    save(content) {
        if (this.history[this.currentIndex] !== content) {
            if (this.currentIndex < this.history.length - 1) {
                this.history = this.history.slice(0, this.currentIndex + 1);
            }

            this.history.push(content);
            
            if (this.history.length > this.maxHistory) {
                this.history.shift();
            }

            this.currentIndex = this.history.length - 1;
        }
    }

    undo() {
        if (this.currentIndex > 0) {
            this.currentIndex--;
            return this.history[this.currentIndex];
        }
        return null;
    }

    redo() {
        if (this.currentIndex < this.history.length - 1) {
            this.currentIndex++;
            return this.history[this.currentIndex];
        }
        return null;
    }
}

const undoManager = new UndoManager();

// 用户唯一标识
const userId = Math.random().toString(36).substr(2, 8);
let serverContent = ''; 
let serverVersion = 0;
let lastModifiedTime = 0;
let isTyping = false;
let pendingSave = false;
let saveTimer = null;
let isPreviewMode = false;

const textarea = document.getElementById('content');
const preview = document.getElementById('preview');
const printable = document.getElementById('printable');
const previewBtn = document.getElementById('previewBtn');
const boldBtn = document.getElementById('boldBtn');
const copyBtn = document.getElementById('copyBtn');
const refreshBtn = document.getElementById('refreshBtn');
const downloadBtn = document.getElementById('downloadBtn');
const undoBtn = document.getElementById('undoBtn');
const redoBtn = document.getElementById('redoBtn');
const saveBtn = document.getElementById('saveBtn');

function showStatus(message, isError) {
    const status = document.getElementById('status');
    status.textContent = message;
    status.style.backgroundColor = isError ? '#f44336' : '#4CAF50';
    status.style.opacity = 1;
    setTimeout(() => {
        status.style.opacity = 0;
    }, 2000);
}

function getServerContent(callback) {
    const request = new XMLHttpRequest();
    request.open('GET', window.location.href + '?raw&_=' + Date.now(), true);
    request.onload = function() {
        if (request.readyState === 4 && request.status === 200) {
            const response = JSON.parse(request.responseText);
            serverContent = response.content;
            serverVersion = response.version;
            if (typeof callback === 'function') {
                callback(serverContent, serverVersion);
            }
        }
    };
    request.onerror = function() {
        showStatus('无法获取服务器内容', true);
    };
    request.send();
}

function saveContent(newContent) {
    if (saveTimer) {
        clearTimeout(saveTimer);
    }
    
    const request = new XMLHttpRequest();
    request.open('POST', window.location.href, true);
    request.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
    request.onload = function() {
        if (request.readyState === 4) {
            if (request.status === 200) {
                const response = JSON.parse(request.responseText);
                serverContent = newContent;
                serverVersion = response.version;
                lastModifiedTime = Date.now();
                showStatus('已保存 ' + new Date().toLocaleTimeString(), false);
            } else if (request.status === 409) {
                // 检测到冲突
                const conflictResponse = JSON.parse(request.responseText);
                handleConflict(conflictResponse.serverContent, conflictResponse.serverVersion);
            } else {
                showStatus('保存失败', true);
            }
            pendingSave = false;
        }
    };
    request.onerror = function() {
        showStatus('网络错误，正在重试...', true);
        pendingSave = false;
        saveTimer = setTimeout(() => saveContent(newContent), 5000);
    };
    request.send('text=' + encodeURIComponent(newContent) + '&version=' + serverVersion + '&userId=' + userId);
}

function handleConflict(serverContent, serverVersion) {
    if (confirm('检测到内容冲突，是否加载服务器最新内容？')) {
        textarea.value = serverContent;
        printable.textContent = serverContent;
        serverContent = serverContent;
        serverVersion = serverVersion;
        undoManager.save(serverContent);
    }
}

function manualSave() {
    const currentContent = textarea.value;
    undoManager.save(currentContent);
    saveContent(currentContent);
    showStatus('手动保存成功', false);
}

function forceRefreshContent() {
    // 强制刷新，不管是否有未保存的修改
    getServerContent(function(content, version) {
        if (textarea.value !== content) {
            textarea.value = content;
            printable.textContent = content;
            serverContent = content;
            serverVersion = version;
            undoManager.save(content);
            showStatus('内容已刷新', false);
        } else {
            showStatus('内容已是最新', false);
        }
    });
}

function debounceSave() {
    if (saveTimer) {
        clearTimeout(saveTimer);
    }
    
    const currentContent = textarea.value;
    if (currentContent !== serverContent) {
        pendingSave = true;
        saveTimer = setTimeout(() => {
            undoManager.save(currentContent);
            saveContent(currentContent);
        }, 500);
    }
}

function copyAllContent() {
    textarea.select();
    document.execCommand('copy');
    showStatus('已复制全部内容', false);
    window.getSelection().removeAllRanges();
}

function downloadFile() {
    const content = textarea.value;
    const blob = new Blob([content], { type: 'text/plain' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = window.location.pathname.split('/').pop() || 'note.txt';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
    showStatus('文件下载中...', false);
}

function undoChanges() {
    const previousContent = undoManager.undo();
    if (previousContent !== null) {
        textarea.value = previousContent;
        printable.textContent = previousContent;
        
        // 自动触发保存
        saveContent(previousContent);
        
        showStatus('已撤销上一步', false);
    } else {
        showStatus('没有可撤销的更改', false);
    }
}

function redoChanges() {
    const nextContent = undoManager.redo();
    if (nextContent !== null) {
        textarea.value = nextContent;
        printable.textContent = nextContent;
        
        // 自动触发保存
        saveContent(nextContent);
        
        showStatus('已重做上一步', false);
    } else {
        showStatus('没有可重做的更改', false);
    }
}

function toggleBoldText() {
    if (isPreviewMode) {
        showStatus('请先退出预览模式', true);
        return;
    }

    const start = textarea.selectionStart;
    const end = textarea.selectionEnd;
    
    if (start === end) {
        showStatus('请先选择要加粗的文本', true);
        return;
    }

    const selectedText = textarea.value.substring(start, end);
    const boldText = `**${selectedText}**`;
    
    const newContent = 
        textarea.value.substring(0, start) + 
        boldText + 
        textarea.value.substring(end);
    
    textarea.value = newContent;
    printable.textContent = newContent;
    
    // 重新设置光标位置
    textarea.setSelectionRange(start, start + boldText.length);
    
    // 触发保存
    undoManager.save(newContent);
    debounceSave();
    
    showStatus('已加粗选中文本', false);
}

function togglePreviewMode() {
    isPreviewMode = !isPreviewMode;
    
    if (isPreviewMode) {
        // 进入预览模式
        textarea.style.display = 'none';
        preview.style.display = 'block';
        
        // 将Markdown风格的**加粗**转换为HTML
        let previewContent = textarea.value
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
            .replace(/\n/g, '<br>');
        
        preview.innerHTML = previewContent;
        previewBtn.innerHTML = '<i class="fas fa-edit"></i>编辑';
    } else {
        // 返回编辑模式
        textarea.style.display = 'block';
        preview.style.display = 'none';
        previewBtn.innerHTML = '<i class="fas fa-eye"></i>预览';
    }
}

function initializeContent() {
    getServerContent(function(content, version) {
        textarea.value = content;
        printable.textContent = content;
        serverContent = content;
        serverVersion = version;
        undoManager.save(content);
    });
}

initializeContent();

textarea.addEventListener('input', () => {
    if (isPreviewMode) return;
    isTyping = true;
    debounceSave();
    printable.textContent = textarea.value;
});

textarea.addEventListener('blur', () => {
    isTyping = false;
    if (saveTimer) {
        clearTimeout(saveTimer);
    }
    if (textarea.value !== serverContent) {
        saveContent(textarea.value);
    }
});

previewBtn.addEventListener('click', togglePreviewMode);
boldBtn.addEventListener('click', toggleBoldText);
copyBtn.addEventListener('click', copyAllContent);
refreshBtn.addEventListener('click', forceRefreshContent);
downloadBtn.addEventListener('click', downloadFile);
undoBtn.addEventListener('click', undoChanges);
redoBtn.addEventListener('click', redoChanges);
saveBtn.addEventListener('click', manualSave);

// 键盘快捷键
document.addEventListener('keydown', (e) => {
    if (isPreviewMode) return;
    
    if ((e.ctrlKey || e.metaKey) && e.key === 'z') {
        e.preventDefault();
        undoChanges();
    }
    if ((e.ctrlKey || e.metaKey) && e.key === 'y') {
        e.preventDefault();
        redoChanges();
    }
    if ((e.ctrlKey || e.metaKey) && e.key === 's') {
        e.preventDefault();
        manualSave();
    }
    if ((e.ctrlKey || e.metaKey) && e.key === 'r') {
        e.preventDefault();
        forceRefreshContent();
    }
    if ((e.ctrlKey || e.metaKey) && e.key === 'b') {
        e.preventDefault();
        toggleBoldText();
    }
    if ((e.ctrlKey || e.metaKey) && e.key === 'p') {
        e.preventDefault();
        togglePreviewMode();
    }
});

textarea.focus();
</script>
</body>
</html>
