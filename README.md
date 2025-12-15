# LLM Text Generator for WordPress

**Elevating Website Documentation through Intelligent Content Extraction**

## 🚀 Project Overview

**LLM Text Generator for WordPress** is an enterprise-grade content extraction and documentation platform that transforms any website into LLM-friendly structured text files. Built specifically for the modern AI ecosystem, this plugin orchestrates seamless website crawling, intelligent content processing, and automated file generation—delivering production-ready `llm.txt` and `llm-full.txt` outputs optimized for ChatGPT, Gemini, Claude, and other large language models.

Leveraging WordPress's robust architecture and modern web technologies, the platform ensures **scalable performance**, **bulletproof data integrity**, and **enterprise-level reliability**. Whether you're optimizing for SEO, preparing content for AI consumption, or building comprehensive website documentation, this solution provides the foundation for seamless digital experiences.

---

## 🛠️ Tech Stack

### **Backend & Core**
![PHP](https://img.shields.io/badge/PHP-8.0+-777BB4?style=for-the-badge&logo=php&logoColor=white)
![WordPress](https://img.shields.io/badge/WordPress-6.0+-21759B?style=for-the-badge&logo=wordpress&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-8.0+-4479A1?style=for-the-badge&logo=mysql&logoColor=white)

### **Frontend & UI**
![JavaScript](https://img.shields.io/badge/JavaScript-ES6+-F7DF1E?style=for-the-badge&logo=javascript&logoColor=black)
![CSS3](https://img.shields.io/badge/CSS3-Custom-1572B6?style=for-the-badge&logo=css3&logoColor=white)
![HTML5](https://img.shields.io/badge/HTML5-Standard-E34F26?style=for-the-badge&logo=html5&logoColor=white)

### **Libraries & Tools**
![JSZip](https://img.shields.io/badge/JSZip-3.10.1-FF6B6B?style=for-the-badge)
![Fetch API](https://img.shields.io/badge/Fetch-API-00D9FF?style=for-the-badge)
![Poppins Font](https://img.shields.io/badge/Font-Poppins-4285F4?style=for-the-badge&logo=google-fonts&logoColor=white)

### **Architecture & Patterns**
- **WordPress AJAX** for asynchronous operations
- **Custom Database Tables** for history management
- **File Locking Mechanisms** for concurrent operation safety
- **Request-Level Registry** for duplicate prevention
- **Atomic File Operations** with `LOCK_EX`

---

## ✨ Key Features

### 🎯 **Intelligent Content Extraction**
Advanced website crawling engine that processes entire sites, extracts structured content, and generates LLM-optimized documentation with intelligent summarization.

### 📄 **Dual Output Formats**
Generate both **summarized** (`llm.txt`) and **complete** (`llm-full.txt`) versions simultaneously, giving you flexibility for different use cases and AI model requirements.

### 💾 **Smart File Management**
Automated file saving directly to WordPress root directory with intelligent backup system. **Zero data loss** guaranteed through timestamped backups and atomic file operations.

### 📚 **Complete History Tracking**
Comprehensive file history system with view, download, and management capabilities. Track every generation with metadata, timestamps, and content previews.

### 🔄 **Intelligent Duplicate Prevention**
Multi-layered duplicate prevention system combining in-memory registries, filesystem checks, and request-level guarantees to ensure **one backup per file, always**.

### 🎨 **Modern Admin Interface**
Polished, responsive admin dashboard with real-time progress tracking, modal dialogs, and smooth user interactions. Built with accessibility and UX best practices.

### ⚡ **Batch Processing Engine**
Efficient batch processing architecture that handles large websites gracefully, with progress indicators and non-blocking operations for optimal performance.

### 🔒 **Enterprise Security**
Built-in permission checks, secure file handling, path validation, and WordPress nonce verification ensuring your content remains protected.

---

## ⚙️ Installation & Setup

### **Prerequisites**

- WordPress **5.0+**
- PHP **7.4+** (PHP 8.0+ recommended)
- MySQL **5.5.5+** (MySQL 8.0+ recommended)
- Active internet connection for website crawling

### **Installation Steps**

1. **Clone or Download the Repository**
   ```bash
   git clone https://github.com/yourusername/llm-text-generator-wordpress.git
   ```

2. **Upload to WordPress**
   ```bash
   # Copy the plugin folder to your WordPress installation
   cp -r llm-text-generator-wordpress/wp-content/plugins/kenil-mangukiya /path/to/wordpress/wp-content/plugins/
   ```

3. **Activate the Plugin**
   - Navigate to **WordPress Admin → Plugins**
   - Find **"LLM Text Generator"** in the plugin list
   - Click **"Activate"**

4. **Access the Dashboard**
   - Go to **WordPress Admin → Kenil Mangukiya**
   - The plugin dashboard will be available immediately

### **Configuration**

No additional configuration required! The plugin works out of the box. However, you can customize:

- **File Output Location**: Files are saved to WordPress root by default (`ABSPATH`)
- **History Retention**: History entries are stored in the database (last 50 entries shown by default)
- **Backup Retention**: Backup files are kept indefinitely (manage manually via file system)

### **Building for Production**

This plugin requires no build process. However, for optimal performance:

1. **Enable WordPress Debugging** (Development only)
   // wp-config.php
   define('WP_DEBUG', false);
   define('WP_DEBUG_LOG', false);
   2. **Optimize Database**
   - Regularly clean up old history entries if needed
   - Monitor backup file storage

3. **Server Configuration**
   - Ensure `file_put_contents()` permissions are correct
   - Verify PHP `max_execution_time` allows for large site processing
   - Configure appropriate PHP memory limits

---

## 🔮 Future Improvements

### **Headless CMS Integration**
Seamless integration with popular headless CMS platforms (Strapi, Contentful, Sanity) to enable direct content synchronization and automated documentation generation.

### **Advanced AI Processing Pipeline**
Integration with OpenAI, Anthropic, and other AI APIs for intelligent content summarization, keyword extraction, and semantic analysis directly within the WordPress ecosystem.

---

## 📖 Usage

### **Basic Workflow**

1. **Enter Website URL**: Paste the target website URL in the input field
2. **Select Output Type**: Choose between summarized, full content, or both
3. **Generate**: Click "LLMs Txt Generator" and wait for processing
4. **Review**: Preview the generated content in the output section
5. **Save**: Click "Save to Website Root" to create files in your WordPress root directory
6. **Manage**: Use "View History" to access previously generated files

### **Output Files**

- **`llm.txt`**: Summarized version optimized for quick AI consumption
- **`llm-full.txt`**: Complete website content with full page details
- **Backup Files**: Automatically created with timestamp format: `filename.backup.YYYY-MM-DD-HH-MM-SS-uuuuuu`

---

## 🏗️ Architecture

### **Database Schema**

The plugin creates a custom table `wp_kmwp_file_history` with the following structure:

- `id` - Primary key
- `user_id` - WordPress user ID
- `website_url` - Source website URL
- `output_type` - Output format (llms_txt, llms_full_txt, llms_both)
- `summarized_content` - LONGTEXT field for summarized content
- `full_content` - LONGTEXT field for full content
- `file_path` - Path to generated files
- `created_at` - Timestamp

### **File Structure**

```
kenil-mangukiya/
├── admin/
│   └── ui.php              # Admin interface template (HTML/PHP)
├── assets/
│   ├── css/
│   │   └── style.css       # Custom styling and UI components
│   ├── js/
│   │   └── script.js       # Frontend JavaScript logic (AJAX, modals, history)
│   └── images/
│       └── logo.jpeg       # Plugin logo
├── index.php               # Main plugin file (hooks, AJAX handlers, database)
└── README.md              # Project documentation
```

### **Core Components**

- **`index.php`**: Main plugin entry point containing WordPress hooks, AJAX endpoints, database operations, and backup management logic
- **`admin/ui.php`**: Admin dashboard interface with form controls, modals, and history display
- **`assets/js/script.js`**: Frontend JavaScript handling user interactions, AJAX calls, file operations, and UI state management
- **`assets/css/style.css`**: Custom CSS for modern, responsive admin interface with modal dialogs and animations
