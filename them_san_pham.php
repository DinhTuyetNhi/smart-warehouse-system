<?php
require_once __DIR__ . '/auth/auth_middleware.php';
$user = requireAuth();
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Thêm sản phẩm</title>
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    <style>
        .preview-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:12px}
        .preview-item{position:relative;border:1px dashed #ccc;padding:6px;border-radius:8px}
        .preview-item img{width:100%;height:140px;object-fit:cover;border-radius:6px}
        .badge{position:absolute;top:6px;left:6px}
        .remove-btn{position:absolute;top:6px;right:6px}
        .hidden{display:none}
    </style>
</head>
<body id="page-top">
    <div class="container mt-4">
        <h3 class="mb-3">Thêm sản phẩm</h3>

        <div class="card mb-4">
            <div class="card-body">
                <div class="mb-2">Chọn ảnh sản phẩm (2-4 ảnh):</div>
                <div class="d-flex gap-2 mb-3">
                    <input id="imageInput" type="file" accept="image/*" multiple class="form-control" style="max-width:360px">
                    <button id="cameraBtn" class="btn btn-secondary"><i class="fas fa-camera"></i> Chụp ảnh</button>
                </div>

                <div id="cameraArea" class="hidden mb-3">
                    <video id="camVideo" width="400" height="300" autoplay muted playsinline class="border rounded"></video>
                    <button id="snapBtn" class="btn btn-sm btn-primary ms-2">Chụp</button>
                    <button id="closeCamBtn" class="btn btn-sm btn-outline-secondary ms-2">Đóng</button>
                    <canvas id="camCanvas" width="800" height="800" class="d-none"></canvas>
                </div>

                <div id="preview" class="preview-grid"></div>
                <div id="uploadErrors" class="text-danger mt-2"></div>

                <button id="analyzeBtn" class="btn btn-primary mt-3" disabled>
                    <span id="analyzeBtnText">Gửi AI gợi ý</span>
                    <span id="analyzeBtnSpinner" class="spinner-border spinner-border-sm ms-2 d-none" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </span>
                </button>
            </div>
        </div>

        <div id="dupAlert" class="alert alert-warning d-none"></div>

        <form id="productForm" class="card d-none">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Tên sản phẩm</label>
                        <input name="name" class="form-control" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Loại (Category ID)</label>
                        <input name="category_id" type="number" class="form-control" min="1" placeholder="tuỳ chọn">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Ngưỡng tồn tối thiểu</label>
                        <input name="min_stock_level" type="number" class="form-control" min="0" value="0">
                    </div>
                    <div class="col-md-12">
                        <label class="form-label">Mô tả</label>
                        <textarea name="description" class="form-control" rows="3"></textarea>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">SKU</label>
                        <input name="sku" class="form-control" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Màu</label>
                        <input name="color" class="form-control">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Size</label>
                        <input name="size" class="form-control">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Giá</label>
                        <input name="price" type="number" step="0.01" class="form-control" value="0">
                    </div>
                    <div class="col-md-12">
                        <label class="form-label">Tags (phân tách bởi dấu phẩy)</label>
                        <input name="tags" class="form-control" placeholder="sport,men,2025">
                    </div>
                </div>
                <div class="mt-3 d-flex gap-2">
                    <button id="saveBtn" type="submit" class="btn btn-success">Lưu</button>
                    <button id="cancelBtn" type="button" class="btn btn-outline-secondary">Hủy</button>
                </div>
            </div>
        </form>
    </div>

    <script src="vendor/jquery/jquery.min.js"></script>
    <script>
    const selectedFiles = [];
    const previewEl = document.getElementById('preview');
    const errorsEl = document.getElementById('uploadErrors');
    const analyzeBtn = document.getElementById('analyzeBtn');
    const formEl = document.getElementById('productForm');
    const dupAlert = document.getElementById('dupAlert');

    function refreshPreview(){
        previewEl.innerHTML = '';
        selectedFiles.forEach((f, idx) => {
            const url = URL.createObjectURL(f);
            const div = document.createElement('div');
            div.className = 'preview-item';
            div.innerHTML = `<span class="badge bg-secondary">${idx+1}</span>
                             <button class="btn btn-sm btn-danger remove-btn">&times;</button>
                             <img src="${url}">`;
            div.querySelector('.remove-btn').onclick = () => { selectedFiles.splice(idx,1); refreshPreview(); };
            previewEl.appendChild(div);
        });
        analyzeBtn.disabled = !(selectedFiles.length >= 2 && selectedFiles.length <= 4);
    }

    document.getElementById('imageInput').addEventListener('change', (e)=>{
        errorsEl.textContent = '';
        const files = Array.from(e.target.files || []);
        for(const f of files){ selectedFiles.push(f); }
        refreshPreview();
    });

    // Camera capture
    const camBtn = document.getElementById('cameraBtn');
    const camArea = document.getElementById('cameraArea');
    const camVideo = document.getElementById('camVideo');
    const camCanvas = document.getElementById('camCanvas');
    const snapBtn = document.getElementById('snapBtn');
    const closeCamBtn = document.getElementById('closeCamBtn');
    let mediaStream;
    camBtn.onclick = async ()=>{
        camArea.classList.remove('hidden');
        try{
            mediaStream = await navigator.mediaDevices.getUserMedia({video:true});
            camVideo.srcObject = mediaStream;
        }catch(err){ alert('Không thể mở camera'); }
    };
    closeCamBtn.onclick = ()=>{ if(mediaStream){ mediaStream.getTracks().forEach(t=>t.stop()); } camArea.classList.add('hidden'); };
    snapBtn.onclick = ()=>{
        const ctx = camCanvas.getContext('2d');
        const size = Math.min(camVideo.videoWidth, camVideo.videoHeight, 1000);
        camCanvas.width = size; camCanvas.height = size;
        // draw center square
        const sx = (camVideo.videoWidth - size)/2;
        const sy = (camVideo.videoHeight - size)/2;
        ctx.drawImage(camVideo, sx, sy, size, size, 0,0, size, size);
        camCanvas.toBlob((blob)=>{
            const f = new File([blob], `camera_${Date.now()}.jpg`, {type:'image/jpeg'});
            selectedFiles.push(f); refreshPreview();
        }, 'image/jpeg', 0.9);
    };

    analyzeBtn.onclick = async ()=>{
        errorsEl.textContent=''; dupAlert.classList.add('d-none');
        
        // Hiển thị trạng thái đang xử lý
        const btnText = document.getElementById('analyzeBtnText');
        const btnSpinner = document.getElementById('analyzeBtnSpinner');
        analyzeBtn.disabled = true;
        btnText.textContent = 'Đang xử lý...';
        btnSpinner.classList.remove('d-none');
        
        try {
            const fd = new FormData();
            selectedFiles.forEach((f)=> fd.append('images[]', f));
            const res = await fetch('product_api.php?action=validate_and_suggest', {method:'POST', body: fd});
            
            // Kiểm tra phản hồi HTTP
            if (!res.ok) {
                throw new Error(`HTTP ${res.status}: ${res.statusText}`);
            }
            
            const responseText = await res.text();
            console.log('Raw response:', responseText);
            
            let json;
            try {
                json = JSON.parse(responseText);
            } catch (parseError) {
                console.error('JSON parse error:', parseError);
                console.error('Response was:', responseText);
                throw new Error('Server trả về dữ liệu không hợp lệ');
            }
            
            if(!json.success){ 
                console.error('API error:', json);
                errorsEl.textContent = json.message || 'Lỗi xử lý'; 
                return; 
            }
            
            // populate form
            formEl.classList.remove('d-none');
            formEl.name.value = json.data.name || '';
            if(json.data.category_id) formEl.category_id.value = json.data.category_id;
            formEl.description.value = json.data.description || '';
            formEl.sku.value = json.data.sku || '';
            formEl.color.value = json.data.color || '';
            formEl.size.value = json.data.size || '';

            // Hiển thị cảnh báo trùng nếu có
            if(json.data.duplicate && json.data.duplicate.score){
                const d = json.data.duplicate;
                const pct = Math.round((d.score||0)*100);
                if(d.is_duplicate){
                    dupAlert.classList.remove('d-none');
                    dupAlert.innerHTML = `Sản phẩm này có thể đã tồn tại (độ tương đồng: ${pct}%). `+
                    `Bạn có thể <a href="#" id="updateOld">cập nhật sản phẩm cũ</a> hoặc tiếp tục thêm mới.`;
                }
            }

            // store token key from temp upload
            formEl.dataset.uploadToken = json.upload_token;
            
            // Hiển thị thông báo thành công
            const successMsg = document.createElement('div');
            successMsg.className = 'alert alert-success mt-2';
            successMsg.innerHTML = '<i class="fas fa-check-circle"></i> AI đã phân tích xong! Vui lòng kiểm tra và chỉnh sửa thông tin bên dưới.';
            analyzeBtn.parentNode.appendChild(successMsg);
            setTimeout(() => successMsg.remove(), 5000);
            
        } catch (error) {
            console.error('AI analysis error:', error);
            let errorMsg = 'Lỗi kết nối. Vui lòng thử lại.';
            
            if (error.message.includes('HTTP')) {
                errorMsg = `Lỗi server: ${error.message}`;
            } else if (error.message.includes('không hợp lệ')) {
                errorMsg = error.message;
            } else if (error.name === 'TypeError' && error.message.includes('fetch')) {
                errorMsg = 'Không thể kết nối đến server. Kiểm tra XAMPP đã chạy chưa.';
            }
            
            errorsEl.textContent = errorMsg;
        } finally {
            // Reset trạng thái nút
            analyzeBtn.disabled = false;
            btnText.textContent = 'Gửi AI gợi ý';
            btnSpinner.classList.add('d-none');
        }
    };

    formEl.addEventListener('submit', async (e)=>{
        e.preventDefault();
        
        // Vô hiệu hóa nút lưu và hiển thị trạng thái
        const saveBtn = document.getElementById('saveBtn');
        const originalText = saveBtn.innerHTML;
        saveBtn.disabled = true;
        saveBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Đang lưu...';
        
        try {
            const fd = new FormData(formEl);
            fd.append('upload_token', formEl.dataset.uploadToken || '');
            const res = await fetch('product_api.php?action=save', {method:'POST', body: fd});
            const json = await res.json();
            
            if(json.success){
                // Hiển thị thông báo thành công
                const successAlert = document.createElement('div');
                successAlert.className = 'alert alert-success';
                successAlert.innerHTML = '<i class="fas fa-check-circle"></i> Thêm sản phẩm thành công! Đang chuyển hướng...';
                formEl.prepend(successAlert);
                
                setTimeout(() => {
                    window.location.href = 'danh_sach_sp.html';
                }, 2000);
            } else {
                // Hiển thị lỗi
                const errorAlert = document.createElement('div');
                errorAlert.className = 'alert alert-danger';
                errorAlert.innerHTML = '<i class="fas fa-exclamation-triangle"></i> ' + (json.message || 'Không thể thêm sản phẩm, vui lòng thử lại');
                formEl.prepend(errorAlert);
                setTimeout(() => errorAlert.remove(), 5000);
            }
        } catch (error) {
            const errorAlert = document.createElement('div');
            errorAlert.className = 'alert alert-danger';
            errorAlert.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Lỗi kết nối. Vui lòng thử lại.';
            formEl.prepend(errorAlert);
            setTimeout(() => errorAlert.remove(), 5000);
            console.error('Save error:', error);
        } finally {
            // Reset nút lưu
            saveBtn.disabled = false;
            saveBtn.innerHTML = originalText;
        }
    });

    document.getElementById('cancelBtn').onclick = ()=>{ window.location.href = 'index.html'; };
    </script>
</body>
</html>
