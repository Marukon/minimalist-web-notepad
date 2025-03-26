<?php
$save_path = '_tmp';
header('Cache-Control: no-store');

if (!isset($_GET['note']) || strlen($_GET['note']) > 64 || !preg_match('/^[a-zA-Z0-9_-]+$/', $_GET['note'])) {
    header("Location: " . substr(str_shuffle('234579abcdefghjkmnpqrstwxyz'), -5));
    die;
}

$path = $save_path . '/' . $_GET['note'];
$version_path = $path . '.version';
$patches_path = $path . '.patches';

if (isset($_GET['poll'])) {
    $clientVersion = intval($_GET['version'] ?? 0);
    $timeout = 25;
    $startTime = time();
    
    while (true) {
        clearstatcache();
        $currentVersion = file_exists($version_path) ? intval(file_get_contents($version_path)) : 0;
        
        if ($currentVersion > $clientVersion) {
            $patches = [];
            if (file_exists($patches_path)) {
                $allPatches = json_decode(file_get_contents($patches_path), true) ?: [];
                foreach ($allPatches as $patch) {
                    if ($patch['version'] > $clientVersion) {
                        $patches[] = $patch;
                    }
                }
            }
            
            header('Content-Type: application/json');
            echo json_encode([
                'version' => $currentVersion,
                'patches' => $patches
            ]);
            exit;
        }
        
        if (time() - $startTime > $timeout) {
            header('Content-Type: application/json');
            echo json_encode(['timeout' => true]);
            exit;
        }
        
        usleep(100000);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents("php://input"), true);
    $text = $data['text'] ?? '';
    $clientVersion = intval($data['version'] ?? 0);
    $userId = $data['userId'] ?? '';
    $patch = $data['patch'] ?? null;

    $serverData = file_exists($path) ? file_get_contents($path) : '';
    $currentVersion = file_exists($version_path) ? intval(file_get_contents($version_path)) : 0;

    if ($patch && is_array($patch)) {
        $text = applyPatch($serverData, $patch);
    }

    if ($clientVersion < $currentVersion) {
        header('HTTP/1.1 409 Conflict');
        echo json_encode([
            'serverContent' => $serverData,
            'serverVersion' => $currentVersion
        ]);
        die;
    }

    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }

    file_put_contents($path, $text);
    $newVersion = $currentVersion + 1;
    file_put_contents($version_path, $newVersion);
    
    $patches = [];
    if (file_exists($patches_path)) {
        $patches = json_decode(file_get_contents($patches_path), true) ?: [];
    }
    
    if ($patch) {
        $patches[] = [
            'version' => $newVersion,
            'patch' => $patch,
            'userId' => $userId,
            'timestamp' => time()
        ];
        if (count($patches) > 50) {
            $patches = array_slice($patches, -50);
        }
        file_put_contents($patches_path, json_encode($patches));
    }

    echo json_encode([
        'version' => $newVersion,
        'userId' => $userId
    ]);
    die;
}

function applyPatch($text, $patch) {
    usort($patch, function($a, $b) {
        return $b['pos'] - $a['pos'];
    });
    
    foreach ($patch as $p) {
        if ($p['op'] === 'insert') {
            $text = substr($text, 0, $p['pos']) . $p['text'] . substr($text, $p['pos']);
        } elseif ($p['op'] === 'delete') {
            $text = substr($text, 0, $p['pos']) . substr($text, $p['pos'] + $p['length']);
        }
    }
    return $text;
}

if (isset($_GET['raw']) || strpos($_SERVER['HTTP_USER_AGENT'], 'curl') === 0 || strpos($_SERVER['HTTP_USER_AGENT'], 'Wget') === 0) {
    if (is_file($path)) {
        header('Content-type: application/json');
        $version = file_exists($version_path) ? file_get_contents($version_path) : 0;
        echo json_encode([
            'content' => file_get_contents($path),
            'version' => $version
        ]);
    } else {
        header('HTTP/1.0 404 Not Found');
    }
    die;
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php print htmlspecialchars($_GET['note'], ENT_QUOTES, 'UTF-8'); ?></title>
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
    background: white;
}
#content {
    resize: none;
}
#preview {
    display: none;
    white-space: pre-wrap;
    word-wrap: break-word;
}
#preview strong {
    font-size: 1.5em;
    color: #333;
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
#undoBtn { background: #F44336; color: white; }
#copyBtn { background: #2196F3; color: white; }
#refreshBtn { background: #9C27B0; color: white; }
#saveBtn { background: #3F51B5; color: white; }
#downloadBtn { background: #607D8B; color: white; }

#toolbar button:hover {
    opacity: 0.9;
    transform: translateY(-2px);
    box-shadow: 0 2px 5px rgba(0,0,0,0.2);
}

#toolbar button:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none;
    box-shadow: none;
}

#toolbar button.saving {
    opacity: 0.7;
    cursor: not-allowed;
}
#toolbar button.saving i {
    animation: spin 1s linear infinite;
}
@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
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
    #preview strong {
        color: #fff;
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
    <button id="boldBtn"><i class="fas fa-bold"></i>加粗</button>
    <button id="undoBtn"><i class="fas fa-undo"></i>撤销</button>
    <button id="copyBtn"><i class="fas fa-copy"></i>复制全部</button>
    <button id="refreshBtn"><i class="fas fa-sync"></i>刷新内容</button>
    <button id="saveBtn"><i class="fas fa-save"></i>手动保存</button>
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
document.addEventListener('DOMContentLoaded', function() {
    const textarea = document.getElementById('content');
    const preview = document.getElementById('preview');
    const statusDiv = document.getElementById('status');
    const previewBtn = document.getElementById('previewBtn');
    const saveBtn = document.getElementById('saveBtn');
    
    let serverVersion = 0;
    let isPreviewMode = false;
    let userId = Math.random().toString(36).substr(2, 8);
    let lastContent = '';
    let pollTimeout = null;
    let isApplyingRemotePatch = false;
    let lastInputTime = 0;
    let inputDelay = 300;
    let history = [];
    let historyIndex = -1;
    
    function initializeContent() {
        fetch(window.location.href + '?raw&_=' + Date.now())
            .then(response => response.json())
            .then(data => {
                textarea.value = data.content;
                lastContent = data.content;
                serverVersion = parseInt(data.version) || 0;
                history = [data.content];
                historyIndex = 0;
                startPolling();
            });
    }
    
    function generatePatch(oldText, newText) {
        if (oldText === newText) return null;
        
        let start = 0;
        while (start < oldText.length && start < newText.length && oldText[start] === newText[start]) {
            start++;
        }
        
        let oldEnd = oldText.length;
        let newEnd = newText.length;
        while (oldEnd > start && newEnd > start && oldText[oldEnd - 1] === newText[newEnd - 1]) {
            oldEnd--;
            newEnd--;
        }
        
        const patch = [];
        if (oldEnd > start) {
            patch.push({
                op: 'delete',
                pos: start,
                length: oldEnd - start
            });
        }
        
        if (newEnd > start) {
            patch.push({
                op: 'insert',
                pos: start,
                text: newText.substring(start, newEnd)
            });
        }
        
        return patch;
    }
    
    function applyRemotePatches(patches) {
        if (isApplyingRemotePatch || !patches || patches.length === 0) return;
        
        isApplyingRemotePatch = true;
        let content = textarea.value;
        const selectionStart = textarea.selectionStart;
        const selectionEnd = textarea.selectionEnd;
        
        patches.sort((a, b) => a.version - b.version);
        
        patches.forEach(patchData => {
            if (patchData.patch) {
                content = applyPatch(content, patchData.patch);
            }
        });
        
        if (content !== textarea.value) {
            textarea.value = content;
            lastContent = content;
            
            const lengthDiff = content.length - textarea.value.length;
            const newSelectionStart = Math.max(0, selectionStart + lengthDiff);
            const newSelectionEnd = Math.max(0, selectionEnd + lengthDiff);
            
            setTimeout(() => {
                textarea.setSelectionRange(newSelectionStart, newSelectionEnd);
            }, 0);
            
            history.push(content);
            historyIndex++;
        }
        
        isApplyingRemotePatch = false;
    }
    
    function applyPatch(text, patch) {
        [...patch].reverse().forEach(p => {
            if (p.op === 'insert') {
                text = text.substring(0, p.pos) + p.text + text.substring(p.pos);
            } else if (p.op === 'delete') {
                text = text.substring(0, p.pos) + text.substring(p.pos + p.length);
            }
        });
        return text;
    }
    
    function startPolling() {
        if (pollTimeout) {
            clearTimeout(pollTimeout);
        }
        
        fetch(`${window.location.href}?poll&version=${serverVersion}&_=${Date.now()}`)
            .then(response => response.json())
            .then(data => {
                if (data.timeout) {
                    pollTimeout = setTimeout(startPolling, 100);
                } else if (data.version > serverVersion) {
                    serverVersion = data.version;
                    applyRemotePatches(data.patches);
                    pollTimeout = setTimeout(startPolling, 100);
                } else {
                    startPolling();
                }
            })
            .catch(error => {
                console.error('Polling error:', error);
                pollTimeout = setTimeout(startPolling, 1000);
            });
    }
    
    async function saveContent(newContent, isManualSave = false) {
        const now = Date.now();
        if (!isManualSave && now - lastInputTime < inputDelay) {
            return;
        }

        const patch = generatePatch(lastContent, newContent);
        if (!patch && !isManualSave) return;

        lastContent = newContent;

        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                text: newContent,
                version: serverVersion,
                userId: userId,
                patch: patch
            })
        });

        if (!response.ok) {
            const errorData = await response.json().catch(() => null);
            const errorMsg = errorData?.message || response.statusText;
            throw new Error(errorMsg || `HTTP错误: ${response.status}`);
        }

        const data = await response.json();
        serverVersion = data.version;
        return data;
    }

    async function manualSave() {
        if (isPreviewMode) {
            showStatus('请在编辑模式下保存', true);
            return;
        }
        
        const originalHTML = saveBtn.innerHTML;
        
        saveBtn.innerHTML = '<i class="fas fa-spinner"></i>保存中...';
        saveBtn.disabled = true;
        
        try {
            const result = await saveContent(textarea.value, true);
            showStatus('手动保存成功 ' + new Date().toLocaleTimeString());
            return result;
        } catch (error) {
            console.error('保存失败:', error);
            showStatus('手动保存失败: ' + (error.message || '服务器错误'), true);
            throw error;
        } finally {
            saveBtn.innerHTML = originalHTML;
            saveBtn.disabled = false;
        }
    }

    function handleConflict(serverContent, serverVersion) {
        textarea.value = serverContent;
        lastContent = serverContent;
        this.serverVersion = serverVersion;
        history.push(serverContent);
        historyIndex++;
        showStatus('已从服务器加载最新内容');
    }
    
    function showStatus(message, isError = false) {
        statusDiv.textContent = message;
        statusDiv.style.backgroundColor = isError ? '#f44336' : '#4CAF50';
        statusDiv.style.opacity = 1;
        setTimeout(() => {
            statusDiv.style.opacity = 0;
        }, 2000);
    }
    
  previewBtn.addEventListener('click', function() {
        isPreviewMode = !isPreviewMode;
        
        if (isPreviewMode) {
            textarea.style.display = 'none';
            preview.style.display = 'block';
            preview.innerHTML = textarea.value
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
                .replace(/\n/g, '<br>');
            this.innerHTML = '<i class="fas fa-edit"></i>编辑';
        } else {
            textarea.style.display = 'block';
            preview.style.display = 'none';
            this.innerHTML = '<i class="fas fa-eye"></i>预览';
        }
    });
    
    document.getElementById('boldBtn').addEventListener('click', function() {
        if (isPreviewMode) {
            showStatus('请在编辑模式下使用加粗功能', true);
            return;
        }
        
        const start = textarea.selectionStart;
        const end = textarea.selectionEnd;
        
        let selectedText = '';
        let newStart = start;
        let newEnd = end;
        
        if (start === end) {
            const content = textarea.value;
            
            while (newStart > 0 && !/\s/.test(content[newStart - 1])) {
                newStart--;
            }
            
            while (newEnd < content.length && !/\s/.test(content[newEnd])) {
                newEnd++;
            }
            
            selectedText = content.substring(newStart, newEnd);
            
            if (!selectedText) {
                showStatus('请选择要加粗的文本或确保光标在单词中', true);
                return;
            }
        } else {
            selectedText = textarea.value.substring(start, end);
            newStart = start;
            newEnd = end;
        }
        
        const newText = textarea.value.substring(0, newStart) + 
                       '**' + selectedText + '**' + 
                       textarea.value.substring(newEnd);
        
        textarea.value = newText;
        saveContent(newText);
        showStatus('已加粗文本');
        
        textarea.setSelectionRange(newStart, newEnd + 4);
        textarea.focus();
    });
    
    document.getElementById('undoBtn').addEventListener('click', function() {
        if (isPreviewMode) {
            showStatus('请在编辑模式下使用撤销功能', true);
            return;
        }
        
        if (historyIndex > 0) {
            historyIndex--;
            textarea.value = history[historyIndex];
            lastContent = history[historyIndex];
            saveContent(history[historyIndex], true);
            showStatus('已撤销上一步操作');
        } else {
            showStatus('没有可撤销的操作', true);
        }
    });
    
    document.getElementById('copyBtn').addEventListener('click', function() {
        textarea.select();
        document.execCommand('copy');
        showStatus('已复制全部内容');
        window.getSelection().removeAllRanges();
    });
    
    document.getElementById('refreshBtn').addEventListener('click', function() {
        initializeContent();
        showStatus('内容已刷新');
    });
    
    saveBtn.addEventListener('click', function() {
        manualSave().catch(e => console.error('保存处理错误:', e));
    });
    
    document.getElementById('downloadBtn').addEventListener('click', function() {
        const blob = new Blob([textarea.value], { type: 'text/plain' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = window.location.pathname.split('/').pop() || 'note.txt';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
        showStatus('文件已下载');
    });
    
    textarea.addEventListener('input', function() {
        if (isApplyingRemotePatch) return;
        
        lastInputTime = Date.now();
        const newContent = this.value;
        
        history = history.slice(0, historyIndex + 1);
        history.push(newContent);
        historyIndex = history.length - 1;
        
        setTimeout(() => {
            if (Date.now() - lastInputTime >= inputDelay) {
                saveContent(newContent);
            }
        }, inputDelay);
    });
    
    initializeContent();
});
</script>
</body>
</html>
