// js/pdf-viewer.js

class PDFViewer {
    constructor(containerId, pdfUrl) {
        this.container = document.getElementById(containerId);
        this.pdfUrl = pdfUrl;
        this.pdfDoc = null;
        this.pageNum = 1;
        this.pageRendering = false;
        this.pageNumPending = null;
        this.scale = 1.5;
        this.canvas = null;
        this.ctx = null;
        this.isTimesNewRomanApplied = false;
    }
    
    init() {
        this.createViewer();
        this.loadPDF();
    }
    
    createViewer() {
        // Créer l'interface du viewer
        this.container.innerHTML = `
            <div class="pdf-viewer-container">
                <div class="pdf-toolbar">
                    <div class="btn-group">
                        <button id="prev" class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-chevron-left"></i> Précédent
                        </button>
                        <span class="page-info mx-2">
                            Page <span id="page_num"></span> / <span id="page_count"></span>
                        </span>
                        <button id="next" class="btn btn-sm btn-outline-secondary">
                            Suivant <i class="fas fa-chevron-right"></i>
                        </button>
                    </div>
                    
                    <div class="btn-group ms-3">
                        <button id="zoom_in" class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-search-plus"></i>
                        </button>
                        <button id="zoom_out" class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-search-minus"></i>
                        </button>
                        <button id="zoom_fit" class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-expand"></i>
                        </button>
                        <button id="zoom_actual" class="btn btn-sm btn-outline-secondary">
                            100%
                        </button>
                    </div>
                    
                    <div class="btn-group ms-3">
                        <button id="toggle_font" class="btn btn-sm btn-outline-primary">
                            <i class="fas fa-font"></i> Times New Roman
                        </button>
                        <button id="download" class="btn btn-sm btn-outline-success">
                            <i class="fas fa-download"></i> Télécharger
                        </button>
                        <button id="print" class="btn btn-sm btn-outline-info">
                            <i class="fas fa-print"></i> Imprimer
                        </button>
                    </div>
                </div>
                
                <div class="pdf-canvas-container mt-3">
                    <canvas id="pdf-canvas"></canvas>
                </div>
                
                <div class="pdf-thumbnails mt-3">
                    <div id="thumbnails" class="d-flex flex-wrap gap-2"></div>
                </div>
            </div>
        `;
        
        // Initialiser les éléments
        this.canvas = document.getElementById('pdf-canvas');
        this.ctx = this.canvas.getContext('2d');
        
        // Événements
        document.getElementById('prev').addEventListener('click', () => this.prevPage());
        document.getElementById('next').addEventListener('click', () => this.nextPage());
        document.getElementById('zoom_in').addEventListener('click', () => this.zoomIn());
        document.getElementById('zoom_out').addEventListener('click', () => this.zoomOut());
        document.getElementById('zoom_fit').addEventListener('click', () => this.zoomFit());
        document.getElementById('zoom_actual').addEventListener('click', () => this.zoomActual());
        document.getElementById('toggle_font').addEventListener('click', () => this.toggleFont());
        document.getElementById('download').addEventListener('click', () => this.downloadPDF());
        document.getElementById('print').addEventListener('click', () => this.printPDF());
        
        // Navigation clavier
        document.addEventListener('keydown', (e) => {
            switch(e.key) {
                case 'ArrowLeft':
                    this.prevPage();
                    break;
                case 'ArrowRight':
                    this.nextPage();
                    break;
                case '+':
                case '=':
                    this.zoomIn();
                    break;
                case '-':
                    this.zoomOut();
                    break;
                case '0':
                    this.zoomFit();
                    break;
            }
        });
    }
    
    async loadPDF() {
        try {
            // Charger le PDF avec PDF.js
            const loadingTask = pdfjsLib.getDocument(this.pdfUrl);
            this.pdfDoc = await loadingTask.promise;
            
            // Afficher la première page
            this.renderPage(this.pageNum);
            
            // Mettre à jour le compteur de pages
            document.getElementById('page_count').textContent = this.pdfDoc.numPages;
            
            // Générer les miniatures
            this.generateThumbnails();
            
        } catch (error) {
            console.error('Erreur de chargement du PDF:', error);
            this.container.innerHTML = `
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i>
                    Impossible de charger le PDF.
                    <a href="${this.pdfUrl}" class="alert-link">Cliquez ici pour télécharger le fichier</a>
                </div>
            `;
        }
    }
    
    async renderPage(num) {
        if (this.pageRendering) {
            this.pageNumPending = num;
            return;
        }
        
        this.pageRendering = true;
        this.pageNum = num;
        
        try {
            const page = await this.pdfDoc.getPage(num);
            const viewport = page.getViewport({ scale: this.scale });
            
            // Ajuster la taille du canvas
            this.canvas.height = viewport.height;
            this.canvas.width = viewport.width;
            
            // Rendre la page
            const renderContext = {
                canvasContext: this.ctx,
                viewport: viewport
            };
            
            await page.render(renderContext).promise;
            
            // Mettre à jour l'interface
            document.getElementById('page_num').textContent = num;
            
            // Mettre à jour la miniature active
            this.updateActiveThumbnail(num);
            
            // Appliquer Times New Roman si activé
            if (this.isTimesNewRomanApplied) {
                this.applyTimesNewRomanToCanvas();
            }
            
        } catch (error) {
            console.error('Erreur de rendu de page:', error);
        }
        
        this.pageRendering = false;
        
        if (this.pageNumPending !== null) {
            this.renderPage(this.pageNumPending);
            this.pageNumPending = null;
        }
    }
    
    prevPage() {
        if (this.pageNum <= 1) return;
        this.renderPage(this.pageNum - 1);
    }
    
    nextPage() {
        if (this.pageNum >= this.pdfDoc.numPages) return;
        this.renderPage(this.pageNum + 1);
    }
    
    zoomIn() {
        this.scale = Math.min(this.scale * 1.2, 5);
        this.renderPage(this.pageNum);
    }
    
    zoomOut() {
        this.scale = Math.max(this.scale / 1.2, 0.5);
        this.renderPage(this.pageNum);
    }
    
    zoomFit() {
        const containerWidth = this.canvas.parentElement.clientWidth;
        const pageWidth = 612; // Largeur standard d'une page A4 en points
        
        this.scale = containerWidth / pageWidth;
        this.renderPage(this.pageNum);
    }
    
    zoomActual() {
        this.scale = 1.5;
        this.renderPage(this.pageNum);
    }
    
    async generateThumbnails() {
        const thumbnailsContainer = document.getElementById('thumbnails');
        
        for (let i = 1; i <= Math.min(this.pdfDoc.numPages, 10); i++) {
            const page = await this.pdfDoc.getPage(i);
            const viewport = page.getViewport({ scale: 0.2 });
            
            const canvas = document.createElement('canvas');
            canvas.width = viewport.width;
            canvas.height = viewport.height;
            canvas.className = 'pdf-thumbnail';
            canvas.dataset.page = i;
            
            if (i === 1) {
                canvas.classList.add('active');
            }
            
            const context = canvas.getContext('2d');
            const renderContext = {
                canvasContext: context,
                viewport: viewport
            };
            
            await page.render(renderContext).promise;
            
            canvas.addEventListener('click', () => {
                this.renderPage(i);
            });
            
            thumbnailsContainer.appendChild(canvas);
        }
    }
    
    updateActiveThumbnail(pageNum) {
        document.querySelectorAll('.pdf-thumbnail').forEach(thumb => {
            thumb.classList.remove('active');
            if (parseInt(thumb.dataset.page) === pageNum) {
                thumb.classList.add('active');
            }
        });
    }
    
    toggleFont() {
        this.isTimesNewRomanApplied = !this.isTimesNewRomanApplied;
        const button = document.getElementById('toggle_font');
        
        if (this.isTimesNewRomanApplied) {
            button.classList.remove('btn-outline-primary');
            button.classList.add('btn-primary');
            button.innerHTML = '<i class="fas fa-font"></i> Times New Roman (Activé)';
            this.applyTimesNewRomanToCanvas();
        } else {
            button.classList.remove('btn-primary');
            button.classList.add('btn-outline-primary');
            button.innerHTML = '<i class="fas fa-font"></i> Times New Roman';
            this.removeTimesNewRomanFromCanvas();
        }
    }
    
    applyTimesNewRomanToCanvas() {
        // Cette fonction est conceptuelle car on ne peut pas changer la police
        // d'un PDF déjà rendu sur canvas. Dans une vraie implémentation, on utiliserait
        // l'API de conversion pour régénérer le PDF avec Times New Roman.
        
        // Pour l'instant, on ajoute juste un overlay avec du texte
        const overlay = document.createElement('div');
        overlay.id = 'font-overlay';
        overlay.style.cssText = `
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            font-family: "Times New Roman", Times, serif;
            font-size: 12pt;
            line-height: 1.5;
            color: rgba(0,0,0,0);
            z-index: 10;
        `;
        overlay.textContent = 'Times New Roman appliqué';
        
        const canvasContainer = this.canvas.parentElement;
        canvasContainer.style.position = 'relative';
        canvasContainer.appendChild(overlay);
        
        // Afficher un message
        this.showNotification('Style Times New Roman appliqué au document', 'info');
    }
    
    removeTimesNewRomanFromCanvas() {
        const overlay = document.getElementById('font-overlay');
        if (overlay) {
            overlay.remove();
        }
        this.showNotification('Style Times New Roman désactivé', 'info');
    }
    
    downloadPDF() {
        // Convertir avec Times New Roman si activé
        let downloadUrl = this.pdfUrl;
        
        if (this.isTimesNewRomanApplied) {
            // Utiliser l'API de conversion
            downloadUrl = `api/pdf_converter.php?action=convert&livre_id=${this.getLivreIdFromUrl()}`;
        }
        
        // Créer un lien de téléchargement
        const link = document.createElement('a');
        link.href = downloadUrl;
        link.download = this.getFilenameFromUrl();
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }
    
    printPDF() {
        // Ouvrir le PDF dans une nouvelle fenêtre pour l'impression
        const printWindow = window.open(this.pdfUrl, '_blank');
        
        if (printWindow) {
            printWindow.onload = function() {
                printWindow.print();
            };
        }
    }
    
    getLivreIdFromUrl() {
        const match = window.location.href.match(/id=(\d+)/);
        return match ? match[1] : 0;
    }
    
    getFilenameFromUrl() {
        const urlParts = this.pdfUrl.split('/');
        return urlParts[urlParts.length - 1];
    }
    
    showNotification(message, type = 'info') {
        const notification = document.createElement('div');
        notification.className = `position-fixed top-0 end-0 p-3`;
        notification.style.zIndex = '1050';
        
        notification.innerHTML = `
            <div class="toast show" role="alert">
                <div class="toast-header bg-${type} text-white">
                    <i class="fas fa-${type === 'success' ? 'check-circle' : 'info-circle'} me-2"></i>
                    <strong class="me-auto">PDF Viewer</strong>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast"></button>
                </div>
                <div class="toast-body">
                    ${message}
                </div>
            </div>
        `;
        
        document.body.appendChild(notification);
        
        setTimeout(() => {
            notification.remove();
        }, 3000);
    }
}

// Initialiser le viewer quand la page est chargée
document.addEventListener('DOMContentLoaded', function() {
    const pdfUrl = document.querySelector('[data-pdf-url]')?.dataset.pdfUrl;
    const containerId = document.querySelector('[data-pdf-viewer]')?.id;
    
    if (pdfUrl && containerId) {
        // Charger PDF.js
        if (typeof pdfjsLib === 'undefined') {
            const script = document.createElement('script');
            script.src = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.min.js';
            script.onload = function() {
                pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.worker.min.js';
                const viewer = new PDFViewer(containerId, pdfUrl);
                viewer.init();
            };
            document.head.appendChild(script);
        } else {
            const viewer = new PDFViewer(containerId, pdfUrl);
            viewer.init();
        }
    }
});