// ============ Lightbox ภาพสินค้า ============
let images = ["image1.jpg", "image2.jpg", "image3.jpg"];
let currentIndex = 0;

function openLightbox(index) {
    currentIndex = index;
    updateLightboxImage();
    document.getElementById("lightbox").style.display = "block";
}

function closeLightbox() {
    document.getElementById("lightbox").style.display = "none";
}

function changeImage(direction) {
    currentIndex = (currentIndex + direction + images.length) % images.length;
    updateLightboxImage();
}

function updateLightboxImage() {
    const lightboxImg = document.getElementById("lightbox-img");
    if (lightboxImg && images[currentIndex]) {
        lightboxImg.src = images[currentIndex];
    }
}

function openChat(productId = '') {
    const url = productId ? `chat.html?product_id=${productId}` : 'chat.html';
    window.location.href = url;
}

