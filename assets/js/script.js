document.addEventListener('DOMContentLoaded', () => {

    if (typeof kmwp_ajax === 'undefined') {
        console.error('kmwp_ajax not found');
        return;
    }

    const ajaxUrl = kmwp_ajax.ajax_url;
    const nonce = kmwp_ajax.nonce;

    /**
     * Reusable AJAX fetch helper
     * Automatically attaches nonce to requests
     * 
     * @param {string} action - The AJAX action name
     * @param {object} options - Fetch options (method, headers, body, etc.)
     * @returns {Promise<Response>} Fetch response
     */
    function apiFetch(action, options = {}) {
        const url = new URL(ajaxUrl);
        url.searchParams.set('action', action);
        url.searchParams.set('nonce', nonce);
        
        // If there are existing query params in options, merge them
        if (options.queryParams) {
            Object.keys(options.queryParams).forEach(key => {
                url.searchParams.set(key, options.queryParams[key]);
            });
            delete options.queryParams;
        }
        
        return fetch(url.toString(), options);
    }

    const websiteUrlInput = document.getElementById('websiteUrl');
    const generateBtn = document.getElementById('generateBtn');
    const statusMessage = document.getElementById('statusMessage');
    const statusMessageText = document.getElementById('statusMessageText');
    const statusMessageClose = document.getElementById('statusMessageClose');
    const outputSection = document.getElementById('outputSection');
    const outputIframe = document.getElementById('outputIframe');
    const copyBtn = document.getElementById('copyBtn');
    const saveToRootBtn = document.getElementById('saveToRootBtn');
    const downloadBtn = document.getElementById('downloadBtn');
    const clearBtn = document.getElementById('clearBtn');
    const toggleButtons = document.querySelectorAll('.toggle-btn');
    const processingOverlay = document.getElementById('processingOverlay');
    const progressBar = document.getElementById('progressBar');
    const processingPercent = document.getElementById('processingPercent');
    const processingDetail = document.getElementById('processingDetail');
    const confirmOverwriteModal = document.getElementById('confirmOverwriteModal');
    const confirmProceedBtn = document.getElementById('confirmProceedBtn');
    const confirmCancelBtn = document.getElementById('confirmCancelBtn');
    const confirmModalCloseBtn = document.getElementById('confirmModalCloseBtn');
    const showHistoryBtn = document.getElementById('showHistoryBtn');
    const refreshHistoryBtn = document.getElementById('refreshHistoryBtn');
    const closeHistoryBtn = document.getElementById('closeHistoryBtn');
    const closeOutputBtn = document.getElementById('closeOutputBtn');
    const historyViewModal = document.getElementById('historyViewModal');
    const historyViewCloseBtn = document.getElementById('historyViewCloseBtn');
    const historyViewCloseBtn2 = document.getElementById('historyViewCloseBtn2');
    const deleteConfirmModal = document.getElementById('deleteConfirmModal');
    const deleteProceedBtn = document.getElementById('deleteProceedBtn');
    const deleteCancelBtn = document.getElementById('deleteCancelBtn');
    const deleteModalCloseBtn = document.getElementById('deleteModalCloseBtn');
    
    let isSaving = false; // Prevent duplicate saves
    let isDeleting = false; // Prevent duplicate deletes
    let currentDeleteId = null; // Store ID for delete confirmation

    let selectedOutputType = 'llms_txt';
    let currentOutputContent = '';
    let currentSummarizedContent = '';
    let currentFullContent = '';
    let storedZipBlob = null;

    /* ------------------------
       Helpers
    -------------------------*/
    function showError(msg) {
        if (statusMessageText) {
            statusMessageText.textContent = `Error: ${msg}`;
        }
        statusMessage.className = 'status-message status-error';
        if (statusMessageClose) {
            statusMessageClose.style.display = 'inline-block';
        }
    }

    function showSuccess(msg) {
        if (statusMessageText) {
            statusMessageText.textContent = msg;
        }
        statusMessage.className = 'status-message status-success';
        if (statusMessageClose) {
            statusMessageClose.style.display = 'inline-block';
        }
    }
    
    function clearStatusMessage() {
        if (statusMessageText) {
            statusMessageText.textContent = '';
        }
        statusMessage.className = 'status-message';
        if (statusMessageClose) {
            statusMessageClose.style.display = 'none';
        }
    }
    
    // Close button for status message
    if (statusMessageClose) {
        statusMessageClose.addEventListener('click', () => {
            clearStatusMessage();
        });
    }

    function showProcessingOverlay() {
        if (processingOverlay) {
            processingOverlay.classList.add('show');
            progressBar.style.width = '0%';
            processingPercent.textContent = '0%';
            processingDetail.textContent = 'Preparing...';
        }
    }

    function hideProcessingOverlay() {
        if (processingOverlay) {
            processingOverlay.classList.remove('show');
        }
    }

    function updateProgress(percent, detail) {
        if (progressBar) {
            progressBar.style.width = percent + '%';
        }
        if (processingPercent) {
            processingPercent.textContent = Math.round(percent) + '%';
        }
        if (processingDetail && detail) {
            processingDetail.textContent = detail;
        }
    }

    function isValidUrl(url) {
        if (!url || typeof url !== 'string') {
            return { valid: false, error: 'URL is required' };
        }
        
        const trimmedUrl = url.trim();
        
        if (trimmedUrl.length === 0) {
            return { valid: false, error: 'URL cannot be empty' };
        }
        
        // Check for minimum length
        if (trimmedUrl.length < 4) {
            return { valid: false, error: 'URL is too short' };
        }
        
        // Check for maximum length (reasonable limit)
        if (trimmedUrl.length > 2048) {
            return { valid: false, error: 'URL is too long (maximum 2048 characters)' };
        }
        
        // Check for spaces in URL (invalid)
        if (trimmedUrl.includes(' ')) {
            return { valid: false, error: 'URL cannot contain spaces' };
        }
        
        // Normalize URL - add protocol if missing
        let normalizedUrl = trimmedUrl;
        if (!trimmedUrl.match(/^https?:\/\//i)) {
            normalizedUrl = 'https://' + trimmedUrl;
        }
        
        try {
            const urlObj = new URL(normalizedUrl);
            
            // Validate protocol
            if (!['http:', 'https:'].includes(urlObj.protocol)) {
                return { valid: false, error: 'URL must use http:// or https:// protocol' };
            }
            
            // Validate hostname exists
            if (!urlObj.hostname || urlObj.hostname.length === 0) {
                return { valid: false, error: 'URL must contain a valid domain name' };
            }
            
            // Validate hostname format
            const hostname = urlObj.hostname.toLowerCase();
            
            // Check for invalid characters in hostname
            if (!/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/i.test(hostname)) {
                return { valid: false, error: 'Invalid domain name format' };
            }
            
            // Check for valid TLD (at least 2 characters after last dot)
            const parts = hostname.split('.');
            if (parts.length < 2) {
                return { valid: false, error: 'URL must contain a valid top-level domain (e.g., .com, .org)' };
            }
            
            const tld = parts[parts.length - 1];
            if (tld.length < 2 || !/^[a-z]{2,}$/i.test(tld)) {
                return { valid: false, error: 'Invalid top-level domain' };
            }
            
            // Check for common invalid patterns
            if (hostname.startsWith('.') || hostname.endsWith('.')) {
                return { valid: false, error: 'Domain name cannot start or end with a dot' };
            }
            
            if (hostname.includes('..')) {
                return { valid: false, error: 'Domain name cannot contain consecutive dots' };
            }
            
            // Check for localhost or IP addresses (optional - you can remove this if you want to allow them)
            // For now, we'll allow localhost and IPs for development
            const isLocalhost = hostname === 'localhost' || hostname === '127.0.0.1' || hostname.startsWith('192.168.') || hostname.startsWith('10.') || hostname.startsWith('172.');
            
            // Validate port if present
            if (urlObj.port) {
                const port = parseInt(urlObj.port, 10);
                if (isNaN(port) || port < 1 || port > 65535) {
                    return { valid: false, error: 'Invalid port number' };
                }
            }
            
            // All validations passed
            return { 
                valid: true, 
                normalizedUrl: normalizedUrl,
                originalUrl: trimmedUrl
            };
            
        } catch (e) {
            // If URL constructor fails, provide helpful error
            if (e instanceof TypeError) {
                return { valid: false, error: 'Invalid URL format. Please include http:// or https://' };
            }
            return { valid: false, error: 'Invalid URL format' };
        }
    }
    
    function validateAndNormalizeUrl(url) {
        const validation = isValidUrl(url);
        if (!validation.valid) {
            return { valid: false, error: validation.error, url: url };
        }
        return { valid: true, url: validation.normalizedUrl, originalUrl: validation.originalUrl };
    }

    function displayContent(content) {
        if (!content) return;
        
        // Remove all leading whitespace (spaces, tabs, etc.) from the entire content
        let cleanedContent = content.trim();
        
        // Split into lines and remove leading spaces from first line
        const lines = cleanedContent.split('\n');
        if (lines.length > 0 && lines[0]) {
            // Remove all leading whitespace characters from first line
            lines[0] = lines[0].replace(/^\s+/, '');
        }
        cleanedContent = lines.join('\n');
        
        currentOutputContent = cleanedContent;
        outputSection.style.display = 'block';

        const iframeDoc = outputIframe.contentDocument || outputIframe.contentWindow.document;
        iframeDoc.open();
        // Write without extra indentation to avoid adding spaces
        iframeDoc.write('<html><body style="white-space:pre-wrap;font-family:monospace">' + 
            cleanedContent.replace(/</g, '&lt;') + 
            '</body></html>');
        iframeDoc.close();

        copyBtn.style.display = 'inline-block';
        downloadBtn.style.display = 'inline-block';
    }

    /* ------------------------
       URL Input Validation
    -------------------------*/
    let urlValidationTimeout = null;
    
    function validateUrlInput(showError = false) {
        const urlInput = websiteUrlInput.value.trim();
        
        // Clear previous timeout
        if (urlValidationTimeout) {
            clearTimeout(urlValidationTimeout);
        }
        
        // If empty, clear any error styling
        if (!urlInput) {
            websiteUrlInput.classList.remove('url-invalid', 'url-valid');
            clearStatusMessage();
            return;
        }
        
        // Debounce validation (wait 500ms after user stops typing)
        urlValidationTimeout = setTimeout(() => {
            const validation = validateAndNormalizeUrl(urlInput);
            
            if (validation.valid) {
                websiteUrlInput.classList.remove('url-invalid');
                websiteUrlInput.classList.add('url-valid');
                // Optionally show success message (but don't spam)
                // clearStatusMessage();
            } else {
                websiteUrlInput.classList.remove('url-valid');
                websiteUrlInput.classList.add('url-invalid');
                if (showError) {
                    showError(validation.error);
                }
            }
        }, 500);
    }
    
    // Real-time validation as user types
    if (websiteUrlInput) {
        websiteUrlInput.addEventListener('input', () => {
            validateUrlInput(false); // Don't show error while typing
        });
        
        // Validate on blur (when user leaves the field)
        websiteUrlInput.addEventListener('blur', () => {
            validateUrlInput(true); // Show error if invalid when leaving field
        });
        
        // Validate on paste
        websiteUrlInput.addEventListener('paste', () => {
            setTimeout(() => {
                validateUrlInput(true);
            }, 100);
        });
    }

    /* ------------------------
       Toggle buttons
    -------------------------*/
    toggleButtons.forEach(btn => {
        btn.addEventListener('click', () => {
            toggleButtons.forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            selectedOutputType = btn.dataset.type;
        });
    });

    /* ------------------------
       Generate
    -------------------------*/
    generateBtn.addEventListener('click', async () => {

        const urlInput = websiteUrlInput.value.trim();

        if (!urlInput) {
            showError('Please enter a URL');
            websiteUrlInput.focus();
            return;
        }
        
        // Validate and normalize URL
        const urlValidation = validateAndNormalizeUrl(urlInput);
        if (!urlValidation.valid) {
            showError(urlValidation.error);
            websiteUrlInput.focus();
            return;
        }
        
        const url = urlValidation.url;
        
        // Update input field with normalized URL if it was modified
        if (url !== urlInput) {
            websiteUrlInput.value = url;
        }

        generateBtn.disabled = true;
        showProcessingOverlay();

        try {
            /* PREPARE */
            updateProgress(0, 'Preparing generation...');
            const prep = await apiFetch('kmwp_prepare_generation', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    websiteUrl: url,
                    outputType: selectedOutputType,
                    userData: null
                })
            });

            if (!prep.ok) throw new Error('Prepare failed');

            const { job_id, total } = await prep.json();
            
            // Update progress after prepare completes
            updateProgress(5, 'Starting batch processing...');

            /* PROCESS BATCHES */
            let processed = 0;
            const batchSize = 5;
            const progressStart = 10;
            const progressEnd = 90;

            while (processed < total) {
                const progress = progressStart + ((processed / total) * (progressEnd - progressStart));
                updateProgress(progress, `Processing batch ${Math.floor(processed / batchSize) + 1}...`);

                const batch = await apiFetch('kmwp_process_batch', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ job_id, start: processed, size: batchSize })
                });

                if (!batch.ok) throw new Error('Batch failed');

                const data = await batch.json();
                processed = data.processed;
            }

            /* FINALIZE */
            updateProgress(90, 'Finalizing...');
            const finalize = await apiFetch('kmwp_finalize', {
                queryParams: { job_id: job_id }
            });
            if (!finalize.ok) throw new Error('Finalize failed');

            const result = await finalize.json();

            updateProgress(100, 'Completed!');

            if (result.is_zip_mode) {
                const bytes = new Uint8Array(result.zip_data.match(/.{1,2}/g).map(b => parseInt(b, 16)));
                storedZipBlob = new Blob([bytes], { type: 'application/zip' });
                // Store both separately
                currentSummarizedContent = result.llms_text || '';
                currentFullContent = result.llms_full_text || '';
                const combinedText = currentSummarizedContent + '\n\n' + currentFullContent;
                displayContent(combinedText);
            } else {
                // Store content based on type
                if (selectedOutputType === 'llms_txt') {
                    currentSummarizedContent = result.llms_text || '';
                    currentOutputContent = currentSummarizedContent;
                } else if (selectedOutputType === 'llms_full_txt') {
                    currentFullContent = result.llms_full_text || '';
                    currentOutputContent = currentFullContent;
            } else {
                    // For both, store separately
                    currentSummarizedContent = result.llms_text || '';
                    currentFullContent = result.llms_full_text || '';
                    currentOutputContent = currentSummarizedContent + '\n\n' + currentFullContent;
                }
                displayContent(currentOutputContent);
            }

            // Hide overlay after a brief delay to show 100%
            setTimeout(() => {
                hideProcessingOverlay();
            showSuccess('Generation completed');
            }, 500);

        } catch (err) {
            hideProcessingOverlay();
            showError(err.message);
        } finally {
            generateBtn.disabled = false;
        }
    });

    /* ------------------------
       Copy to Clipboard
    -------------------------*/
    copyBtn.addEventListener('click', async () => {
        
        if (!currentOutputContent && !currentSummarizedContent && !currentFullContent) {
            showError('No content to copy. Please generate content first.');
            return;
        }
        
        // Determine which content to copy based on output type
        let contentToCopy = '';
        if (selectedOutputType === 'llms_both') {
            // For both, copy the combined content
            contentToCopy = currentOutputContent || (currentSummarizedContent + '\n\n' + currentFullContent);
        } else if (selectedOutputType === 'llms_txt') {
            contentToCopy = currentSummarizedContent || currentOutputContent || '';
        } else if (selectedOutputType === 'llms_full_txt') {
            contentToCopy = currentFullContent || currentOutputContent || '';
        } else {
            contentToCopy = currentOutputContent || '';
        }
        
        if (!contentToCopy) {
            showError('No content to copy.');
            return;
        }
        
        // Store original button text
        const originalText = copyBtn.textContent;
        
        try {
            // Use Clipboard API (modern approach)
            if (navigator.clipboard && navigator.clipboard.writeText) {
                await navigator.clipboard.writeText(contentToCopy);
                
                // Change button text to "Copied!"
                copyBtn.textContent = 'Copied!';
                copyBtn.style.background = '#16a34a'; // Green color
                
                // Show success message
                showSuccess('Content copied to clipboard successfully!');
                
                // Reset button after 2 seconds
                setTimeout(() => {
                    copyBtn.textContent = originalText;
                    copyBtn.style.background = ''; // Reset to original
                }, 2000);
                
            } else {
                // Fallback for older browsers
                const textArea = document.createElement('textarea');
                textArea.value = contentToCopy;
                textArea.style.position = 'fixed';
                textArea.style.left = '-999999px';
                textArea.style.top = '-999999px';
                document.body.appendChild(textArea);
                textArea.focus();
                textArea.select();
                
                try {
                    const successful = document.execCommand('copy');
                    if (successful) {
                        // Change button text to "Copied!"
                        copyBtn.textContent = 'Copied!';
                        copyBtn.style.background = '#16a34a'; // Green color
                        
                        // Show success message
                        showSuccess('Content copied to clipboard successfully!');
                        
                        // Reset button after 2 seconds
                        setTimeout(() => {
                            copyBtn.textContent = originalText;
                            copyBtn.style.background = ''; // Reset to original
                        }, 2000);
                    } else {
                        throw new Error('Copy command failed');
                    }
                } catch (err) {
                    throw new Error('Failed to copy. Please try selecting and copying manually.');
                } finally {
                    document.body.removeChild(textArea);
                }
            }
        } catch (err) {
            showError(err.message);
        }
    });

    /* ------------------------
       Download
    -------------------------*/
    downloadBtn.addEventListener('click', () => {

        if (selectedOutputType === 'llms_both' && storedZipBlob) {
            const url = URL.createObjectURL(storedZipBlob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'llms-both.zip';
            a.click();
            URL.revokeObjectURL(url);
            return;
        }

        const blob = new Blob([currentOutputContent], { type: 'text/plain' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = selectedOutputType === 'llms_full_txt' ? 'llm-full.txt' : 'llm.txt';
        a.click();
        URL.revokeObjectURL(url);
    });

    /* ------------------------
       Function to proceed with save
    -------------------------*/
    async function proceedWithSave(filesExist, userConfirmed) {
        // Prevent duplicate saves
        if (isSaving) {
            return;
        }
        
        isSaving = true;
        saveToRootBtn.disabled = true;
        showProcessingOverlay();
        updateProgress(0, 'Saving file to website root...');
        
        try {
            // Prepare data based on output type
            // Only set confirm_overwrite to true if files exist AND user confirmed
            let saveData = {
                output_type: selectedOutputType,
                confirm_overwrite: filesExist && userConfirmed,
                website_url: websiteUrlInput.value.trim()
            };
            
            if (selectedOutputType === 'llms_both') {
                // For both, send both contents separately
                saveData.summarized_content = currentSummarizedContent || '';
                saveData.full_content = currentFullContent || '';
            } else if (selectedOutputType === 'llms_txt') {
                saveData.content = currentSummarizedContent || currentOutputContent || '';
            } else if (selectedOutputType === 'llms_full_txt') {
                saveData.content = currentFullContent || currentOutputContent || '';
            } else {
                saveData.content = currentOutputContent || '';
            }
            
            const response = await apiFetch('kmwp_save_to_root', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(saveData)
            });
            
            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.data?.message || 'Failed to save file');
            }
            
            updateProgress(100, 'File saved successfully!');
            
            const result = await response.json();
            
            // Hide overlay after brief delay
            setTimeout(() => {
                hideProcessingOverlay();
                let message = result.data.files_saved 
                    ? `Files saved successfully! ${result.data.files_saved.join(', ')}`
                    : `File saved successfully! Accessible at: ${result.data.file_url}`;
                
                if (result.data.backups_created && result.data.backups_created.length > 0) {
                    message += `\n\nBackup files created: ${result.data.backups_created.join(', ')}`;
                } else if (result.data.backup_created) {
                    message += `\n\nBackup file created: ${result.data.backup_created}`;
                }
                
                showSuccess(message);
            }, 1000);
            
        } catch (err) {
            hideProcessingOverlay();
            showError(err.message);
        } finally {
            isSaving = false;
            saveToRootBtn.disabled = false;
        }
    }

    /* ------------------------
       Save to Website Root
    -------------------------*/
    saveToRootBtn.addEventListener('click', async () => {
        // Prevent duplicate clicks
        if (isSaving) {
            console.log('Save operation already in progress, ignoring click');
            return;
        }
        
        if (!currentOutputContent && !currentSummarizedContent && !currentFullContent) {
            showError('No content to save. Please generate content first.');
            return;
        }
        
        // First, check if files exist
        let filesExist = false;
        let userConfirmed = false;
        let existingFilesList = [];
        
        try {
            const checkResponse = await apiFetch('kmwp_check_files_exist', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    output_type: selectedOutputType
                })
            });
            
            if (checkResponse.ok) {
                const checkResult = await checkResponse.json();
                
                if (checkResult.data.files_exist) {
                    filesExist = true;
                    existingFilesList = checkResult.data.existing_files;
                    
                    // Show custom confirmation modal
                    const fileListItems = document.getElementById('confirmFileListItems');
                    const backupExample = document.getElementById('backupExample');
                    
                    // Clear previous items
                    fileListItems.innerHTML = '';
                    
                    // Add file list items
                    checkResult.data.existing_files.forEach(file => {
                        const li = document.createElement('li');
                        li.textContent = file;
                        fileListItems.appendChild(li);
                    });
                    
                    // Set backup example
                    const exampleFile = checkResult.data.existing_files[0];
                    const timestamp = new Date().toISOString().replace(/[-:]/g, '-').split('.')[0].replace('T', '-');
                    backupExample.textContent = `Example: ${exampleFile}.backup.${timestamp}`;
                    
                    // Show modal
                    if (confirmOverwriteModal) {
                        confirmOverwriteModal.classList.add('show');
                    }
                    
                    // Wait for user confirmation
                    return new Promise((resolve) => {
                        const handleConfirm = () => {
                            // Clean up event listeners
                            confirmProceedBtn.removeEventListener('click', handleConfirm);
                            confirmCancelBtn.removeEventListener('click', handleCancel);
                            confirmModalCloseBtn.removeEventListener('click', handleCancel);
                            
                            // Hide modal
                            if (confirmOverwriteModal) {
                                confirmOverwriteModal.classList.remove('show');
                            }
                            
                            userConfirmed = true;
                            // Continue with save
                            proceedWithSave(filesExist, userConfirmed);
                        };
                        
                        const handleCancel = () => {
                            // Clean up event listeners
                            confirmProceedBtn.removeEventListener('click', handleConfirm);
                            confirmCancelBtn.removeEventListener('click', handleCancel);
                            confirmModalCloseBtn.removeEventListener('click', handleCancel);
                            
                            // Hide modal
                            if (confirmOverwriteModal) {
                                confirmOverwriteModal.classList.remove('show');
                            }
                            
                            showError('Save canceled. Existing files were not modified.');
                            saveToRootBtn.disabled = false;
                        };
                        
                        // Add event listeners
                        confirmProceedBtn.addEventListener('click', handleConfirm);
                        confirmCancelBtn.addEventListener('click', handleCancel);
                        confirmModalCloseBtn.addEventListener('click', handleCancel);
                    });
                }
            }
        } catch (err) {
            console.error('Error checking files:', err);
            // Continue anyway if check fails
        }
        
        // If no files exist, proceed directly
        proceedWithSave(filesExist, userConfirmed);
    });

    /* ------------------------
       Clear
    -------------------------*/
    clearBtn.addEventListener('click', () => {
        websiteUrlInput.value = '';
        websiteUrlInput.classList.remove('url-invalid', 'url-valid');
        outputSection.style.display = 'none';
        clearStatusMessage();
        currentOutputContent = '';
        currentSummarizedContent = '';
        currentFullContent = '';
        storedZipBlob = null;
        // Do NOT reset the output type selection - preserve selectedOutputType
        // Do NOT reset toggle buttons - they should remain as selected
    });
    
    // Close output section button
    if (closeOutputBtn) {
        closeOutputBtn.addEventListener('click', () => {
            outputSection.style.display = 'none';
        });
    }

    /* ------------------------
       History Functions
    -------------------------*/
    async function loadHistory(showLoader = false) {
        const historyLoader = document.getElementById('historyLoader');
        const historyList = document.getElementById('historyList');
        
        // Show loader
        if (historyLoader) {
            historyLoader.style.display = 'flex';
        }
        if (historyList) {
            historyList.style.display = 'none';
        }
        
        try {
            const response = await apiFetch('kmwp_get_history');
            if (!response.ok) {
                const errorText = await response.text();
                console.error('History response error:', response.status, errorText);
                throw new Error('Failed to load history: ' + response.status);
            }
            
            const result = await response.json();
            console.log('History result:', result);
            
            if (!result.success) {
                console.error('History API error:', result.data);
                throw new Error(result.data?.message || 'Failed to load history');
            }
            
            displayHistory(result.data || []);
            return Promise.resolve();
        } catch (err) {
            console.error('Error loading history:', err);
            showError('Failed to load history: ' + err.message);
            return Promise.reject(err);
        } finally {
            // Hide loader
            if (historyLoader) {
                historyLoader.style.display = 'none';
            }
            if (historyList) {
                historyList.style.display = 'block';
            }
        }
    }

    function displayHistory(history) {
        const historyList = document.getElementById('historyList');
        const historySection = document.getElementById('historySection');
        
        if (!historyList || !historySection) {
            console.error('History elements not found');
            return;
        }
        
        console.log('Displaying history:', history.length, 'items');
        
        if (history.length === 0) {
            historyList.innerHTML = '<p class="no-history">No history found.</p>';
            return;
        }
        
        historyList.innerHTML = history.map(item => {
            const date = new Date(item.created_at).toLocaleString();
            const fileType = item.output_type === 'llms_both' ? 'Both' : 
                            item.output_type === 'llms_full_txt' ? 'Full' : 'Summarized';
            
            // Determine filename(s) from file_path or default based on output type
            let filename = '';
            let hasBackup = false;
            if (item.file_path) {
                // Extract filenames from file_path (handles both single and multiple files)
                const paths = item.file_path.split(', ');
                const filenameParts = paths.map(path => {
                    const name = path.trim();
                    // Extract just the filename (handle backup files)
                    const parts = name.split(/[/\\]/);
                    const extractedName = parts[parts.length - 1];
                    // Check if it's a backup file
                    if (extractedName.includes('.backup.')) {
                        hasBackup = true;
                    }
                    return extractedName;
                });
                filename = filenameParts.join(', ');
            } else {
                // Fallback to default filenames
                if (item.output_type === 'llms_both') {
                    filename = 'llm.txt, llm-full.txt';
                } else if (item.output_type === 'llms_full_txt') {
                    filename = 'llm-full.txt';
                } else {
                    filename = 'llm.txt';
                }
            }
            
            // Format filename display - add icon if any file is a backup
            const filenameDisplay = hasBackup 
                ? `<span class="backup-icon">📦</span> ${escapeHtml(filename)}`
                : escapeHtml(filename);
            
            return `
                <div class="history-item">
                    <div class="history-info">
                        <div class="history-url">${escapeHtml(item.website_url)}</div>
                        <div class="history-filename" title="${escapeHtml(filename)}">${filenameDisplay}</div>
                        <div class="history-meta">
                            <span class="history-type">${fileType}</span>
                            <span class="history-date">${date}</span>
                        </div>
                    </div>
                    <div class="history-actions">
                        <button class="btn-view" data-id="${item.id}">View</button>
                        <button class="btn-download-history" data-id="${item.id}" data-type="${item.output_type}">Download</button>
                        <button class="btn-delete-history" data-id="${item.id}" data-type="${item.output_type}">Delete</button>
                    </div>
                </div>
            `;
        }).join('');
        
        console.log('History HTML generated, length:', historyList.innerHTML.length);
        
        // Use event delegation - attach listener to historySection (parent) to avoid duplicate listeners
        // Remove any existing listener by using a single delegated listener
        if (historySection) {
            // Remove old listener if exists, then add new one
            historySection.removeEventListener('click', handleHistoryClick);
            historySection.addEventListener('click', handleHistoryClick);
        }
        
        // Ensure history section is visible
        if (historySection) {
            historySection.style.display = 'block';
        }
        
        console.log('History displayed, section visible:', historySection.style.display);
    }
    
    // Event handler for history actions (event delegation)
    function handleHistoryClick(e) {
        const target = e.target.closest('.btn-view, .btn-download-history, .btn-delete-history');
        if (!target) return;
        
        const id = target.dataset.id;
        if (target.classList.contains('btn-view')) {
            viewHistoryItem(id);
        } else if (target.classList.contains('btn-download-history')) {
            downloadHistoryItem(id, target.dataset.type);
        } else if (target.classList.contains('btn-delete-history')) {
            deleteHistoryItem(id);
        }
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    let isViewingHistory = false; // Prevent multiple simultaneous views
    
    async function viewHistoryItem(id) {
        // Prevent multiple simultaneous clicks
        if (isViewingHistory) {
            return;
        }
        
        isViewingHistory = true;
        
        // Find the button
        const btn = document.querySelector(`.btn-view[data-id="${id}"]`);
        const originalHTML = btn ? btn.innerHTML : 'View';
        const originalText = btn ? btn.textContent.trim() : 'View';
        if (btn) {
            btn.disabled = true;
        }
        
        try {
            const response = await apiFetch('kmwp_get_history_item', {
                queryParams: { id: id }
            });
            if (!response.ok) throw new Error('Failed to load history item');
            
            const result = await response.json();
            const item = result.data;
            
            // Get content and format with titles for "Both" type
            let content = '';
            if (item.output_type === 'llms_both') {
                // Extract domain from URL for title
                const urlObj = new URL(item.website_url);
                const domain = urlObj.hostname.replace('www.', '');
                
                // Get content and remove existing titles if present
                let summarized = (item.summarized_content || '').trim();
                let full = (item.full_content || '').trim();
                
                // Escape domain for regex
                const escapedDomain = domain.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                
                // Remove ALL existing title variations from summarized content (anywhere in content, not just start)
                // Match: "# domain llm.txt", "# domain llms.txt", etc.
                const summarizedTitlePatterns = [
                    new RegExp(`#\\s*${escapedDomain}\\s+llm[s]?\\.txt\\s*\\n?`, 'gi'),
                    new RegExp(`#\\s*${escapedDomain}\\s+llm\\.txt\\s*\\n?`, 'gi'),
                    new RegExp(`#\\s*${escapedDomain}\\s+llms\\.txt\\s*\\n?`, 'gi')
                ];
                summarizedTitlePatterns.forEach(pattern => {
                    summarized = summarized.replace(pattern, '').trim();
                });
                
                // Remove ALL existing title variations from full content (anywhere in content, not just start)
                // Match: "# domain llm-full.txt", "# domain llms-full.txt", "# domain llm-full.txt", etc.
                // Remove ALL occurrences, not just the first one
                const fullTitlePatterns = [
                    new RegExp(`#\\s*${escapedDomain}\\s+llm[s-]?full\\.txt\\s*\\n?`, 'gi'),
                    new RegExp(`#\\s*${escapedDomain}\\s+llm-full\\.txt\\s*\\n?`, 'gi'),
                    new RegExp(`#\\s*${escapedDomain}\\s+llms-full\\.txt\\s*\\n?`, 'gi'),
                    new RegExp(`#\\s*${escapedDomain}\\s+llm[s]?full\\.txt\\s*\\n?`, 'gi')
                ];
                fullTitlePatterns.forEach(pattern => {
                    // Replace all occurrences (global flag 'g' handles this)
                    full = full.replace(pattern, '').trim();
                });
                
                // Additional cleanup: remove any remaining title-like patterns
                // This catches any variations we might have missed
                full = full.replace(new RegExp(`^#\\s*${escapedDomain}\\s+.*?full.*?\\s*\\n?`, 'gi'), '').trim();
                
                // Format with titles (only add if not already present)
                content = `# ${domain} llm.txt\n\n${summarized}\n\n\n# ${domain} llm-full.txt\n\n${full}`;
            } else if (item.output_type === 'llms_txt') {
                content = item.summarized_content || '';
            } else {
                content = item.full_content || '';
            }
            
            // Clean content (remove leading spaces)
            let cleanedContent = content.trim();
            const lines = cleanedContent.split('\n');
            if (lines.length > 0 && lines[0]) {
                lines[0] = lines[0].replace(/^\s+/, '');
            }
            cleanedContent = lines.join('\n');
            
            // Update modal content
            const historyViewTitle = document.getElementById('historyViewTitle');
            const historyViewUrl = document.getElementById('historyViewUrl');
            const historyViewIframe = document.getElementById('historyViewIframe');
            
            if (historyViewTitle) historyViewTitle.textContent = 'View File Content';
            if (historyViewUrl) historyViewUrl.textContent = item.website_url;
            
            // Display content in iframe with improved formatting
            if (historyViewIframe) {
                const iframeDoc = historyViewIframe.contentDocument || historyViewIframe.contentWindow.document;
                iframeDoc.open();
                
                // Escape HTML and format content properly
                const escapedContent = cleanedContent
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/\n/g, '<br>');
                
                // Create styled HTML with better formatting
                const htmlContent = `
                    <!DOCTYPE html>
                    <html>
                    <head>
                        <meta charset="UTF-8">
                        <style>
                            body {
                                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                                font-size: 14px;
                                line-height: 1.6;
                                color: #333;
                                padding: 20px;
                                padding-top: 20px;
                                padding-bottom: 30px;
                                margin: 0;
                                background: #fff;
                                white-space: pre-wrap;
                                word-wrap: break-word;
                            }
                            h1, h2, h3, h4, h5, h6 {
                                color: #5B41FA;
                                margin-top: 20px;
                                margin-bottom: 12px;
                                font-weight: 600;
                            }
                            h1 {
                                font-size: 24px;
                                border-bottom: 2px solid #5B41FA;
                                padding-bottom: 8px;
                            }
                            h2 {
                                font-size: 20px;
                                border-bottom: 1px solid #e2e8f0;
                                padding-bottom: 6px;
                            }
                            h3 {
                                font-size: 18px;
                            }
                            code {
                                background: #f1f5f9;
                                padding: 2px 6px;
                                border-radius: 3px;
                                font-family: 'Courier New', monospace;
                                font-size: 13px;
                            }
                            pre {
                                background: #f8f9fa;
                                border: 1px solid #e2e8f0;
                                border-radius: 6px;
                                padding: 15px;
                                overflow-x: auto;
                                margin: 15px 0;
                            }
                            a {
                                color: #5B41FA;
                                text-decoration: none;
                            }
                            a:hover {
                                text-decoration: underline;
                            }
                            ul, ol {
                                margin: 10px 0;
                                padding-left: 25px;
                            }
                            li {
                                margin: 5px 0;
                            }
                            strong {
                                font-weight: 600;
                                color: #1e293b;
                            }
                            hr {
                                border: none;
                                border-top: 1px solid #e2e8f0;
                                margin: 20px 0;
                            }
                        </style>
                    </head>
                    <body>${escapedContent}</body>
                    </html>
                `;
                
                iframeDoc.write(htmlContent);
                iframeDoc.close();
            }
            
            // Show modal
            if (historyViewModal) {
                historyViewModal.classList.add('show');
            }
            
        } catch (err) {
            showError(err.message);
        } finally {
            // Always reset button immediately
            if (btn) {
                btn.disabled = false;
            }
            isViewingHistory = false;
        }
    }
    
    // Close history view modal
    if (historyViewCloseBtn) {
        historyViewCloseBtn.addEventListener('click', () => {
            if (historyViewModal) {
                historyViewModal.classList.remove('show');
            }
        });
    }
    
    if (historyViewCloseBtn2) {
        historyViewCloseBtn2.addEventListener('click', () => {
            if (historyViewModal) {
                historyViewModal.classList.remove('show');
            }
        });
    }

    async function downloadHistoryItem(id, outputType) {
        // Find the button
        const btn = document.querySelector(`.btn-download-history[data-id="${id}"]`);
        const originalHTML = btn ? btn.innerHTML : 'Download';
        const originalText = btn ? btn.textContent.trim() : 'Download';
        if (btn) {
            btn.disabled = true;
        }
        
        try {
            const response = await apiFetch('kmwp_get_history_item', {
                queryParams: { id: id }
            });
            if (!response.ok) throw new Error('Failed to load history item');
            
            const result = await response.json();
            const item = result.data;
            
            if (item.output_type === 'llms_both') {
                // Create zip file with both files
                if (typeof JSZip !== 'undefined') {
                    const zip = new JSZip();
                    zip.file('llm.txt', item.summarized_content || '');
                    zip.file('llm-full.txt', item.full_content || '');
                    
                    const zipBlob = await zip.generateAsync({ type: 'blob' });
                    const url = URL.createObjectURL(zipBlob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = 'llms-both.zip';
                    a.click();
                    URL.revokeObjectURL(url);
                } else {
                    // Fallback: download as single file
                    const content = (item.summarized_content || '') + '\n\n' + (item.full_content || '');
                    const blob = new Blob([content], { type: 'text/plain' });
                    const url = URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = 'llm-both.txt';
                    a.click();
                    URL.revokeObjectURL(url);
                }
            } else if (item.output_type === 'llms_txt') {
                const blob = new Blob([item.summarized_content || ''], { type: 'text/plain' });
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = 'llm.txt';
                a.click();
                URL.revokeObjectURL(url);
            } else {
                const blob = new Blob([item.full_content || ''], { type: 'text/plain' });
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = 'llm-full.txt';
                a.click();
                URL.revokeObjectURL(url);
            }
            
            showSuccess('File downloaded');
        } catch (err) {
            showError(err.message);
        } finally {
            // Always reset button
            if (btn) {
                btn.disabled = false;
            }
        }
    }

    async function deleteHistoryItem(id) {
        // Store ID for confirmation
        currentDeleteId = id;
        
        // Show custom confirmation modal
        if (deleteConfirmModal) {
            deleteConfirmModal.classList.add('show');
        }
    }
    
    async function proceedWithDelete() {
        // Prevent duplicate delete operations
        if (isDeleting) {
            console.log('Delete operation already in progress, ignoring duplicate call');
            return;
        }
        
        if (!currentDeleteId) {
            console.log('No delete ID set, ignoring delete call');
            return;
        }
        
        isDeleting = true;
        const id = currentDeleteId;
        currentDeleteId = null;
        
        // Find the button and add loader
        const btn = document.querySelector(`.btn-delete-history[data-id="${id}"]`);
        const originalHTML = btn ? btn.innerHTML : 'Delete';
        const originalText = btn ? btn.textContent.trim() : 'Delete';
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<span class="button-loader"></span> Deleting...';
        }
        
        // Hide modal
        if (deleteConfirmModal) {
            deleteConfirmModal.classList.remove('show');
        }
        
        try {
            const formData = new FormData();
            formData.append('id', id);
            
            const response = await apiFetch('kmwp_delete_history_item', {
                method: 'POST',
                body: formData
            });
            
            // Handle response - check if it's already deleted (404) or other errors
            if (!response.ok) {
                const errorData = await response.json().catch(() => ({ data: { message: 'Unknown error' } }));
                const errorMessage = errorData.data?.message || 'Failed to delete history item';
                
                // If 404, the item might have been deleted already (race condition)
                // Check if it still exists in the history before showing error
                if (response.status === 404) {
                    console.log('Delete returned 404, checking if item still exists...');
                    // Refresh history - if item is gone, it was successfully deleted
                    await loadHistory(false);
                    // Check if the item still exists
                    const itemStillExists = document.querySelector(`.btn-delete-history[data-id="${id}"]`);
                    if (!itemStillExists) {
                        // Item is gone, deletion was successful (handled by another request)
                        // Don't show message for duplicate requests
                        console.log('Item already deleted by another request, silently completing');
                        await loadHistory(false);
                        return;
                    }
                }
                
                throw new Error(errorMessage);
            }
            
            const result = await response.json();
            let message = result.data.message || 'History item deleted';
            
            // Check if this is the "already deleted" message (from duplicate request)
            // If so, don't show it - the first request already handled it
            if (message === 'History item already deleted' || message.includes('already deleted')) {
                console.log('Duplicate delete request detected, silently ignoring');
                await loadHistory(false);
                return;
            }
            
            // Show success if files were deleted, warning if some failed
            if (result.data.files_failed && result.data.files_failed.length > 0) {
                showError(message); // Show as error if files failed to delete
            } else {
                showSuccess(message);
            }
            await loadHistory(false);
        } catch (err) {
            console.error('Delete error:', err);
            showError(err.message);
        } finally {
            // Always reset button and flag
            setTimeout(() => {
                if (btn) {
                    btn.disabled = false;
                    btn.textContent = originalText;
                    btn.innerHTML = originalText; // Also reset innerHTML to ensure no loader remains
                }
                isDeleting = false;
            }, 0);
        }
    }
    
    // Delete confirmation modal handlers
    if (deleteProceedBtn) {
        deleteProceedBtn.addEventListener('click', () => {
            // Prevent duplicate clicks
            if (isDeleting) {
                console.log('Delete already in progress, ignoring click');
                return;
            }
            proceedWithDelete();
        });
    }
    
    if (deleteCancelBtn) {
        deleteCancelBtn.addEventListener('click', () => {
            currentDeleteId = null;
            if (deleteConfirmModal) {
                deleteConfirmModal.classList.remove('show');
            }
        });
    }
    
    if (deleteModalCloseBtn) {
        deleteModalCloseBtn.addEventListener('click', () => {
            currentDeleteId = null;
            if (deleteConfirmModal) {
                deleteConfirmModal.classList.remove('show');
            }
        });
    }

    // Show history button
    if (showHistoryBtn) {
        showHistoryBtn.addEventListener('click', async () => {
            const historySection = document.getElementById('historySection');
            if (historySection) {
                historySection.style.display = 'block';
                // Load history when section is shown
                await loadHistory(false);
            }
        });
    }
    
    // Close history button
    if (closeHistoryBtn) {
        closeHistoryBtn.addEventListener('click', () => {
            const historySection = document.getElementById('historySection');
            if (historySection) {
                historySection.style.display = 'none';
            }
        });
    }

    // Refresh history button
    if (refreshHistoryBtn) {
        refreshHistoryBtn.addEventListener('click', async () => {
            const originalHTML = refreshHistoryBtn.innerHTML;
            const originalText = refreshHistoryBtn.textContent.trim();
            refreshHistoryBtn.disabled = true;
            
            try {
                await loadHistory(false);
            } catch (err) {
                console.error('Error refreshing history:', err);
            } finally {
                // Always reset button - use setTimeout to ensure it happens after any async operations
                setTimeout(() => {
                    refreshHistoryBtn.disabled = false;
                }, 0);
            }
        });
    }
});
