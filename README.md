# Mortgage Document Management ERP System

A production-ready document management system for mortgage loan processing that automates document collection, storage, and reporting. The system integrates with external APIs to download loan documents, stores them securely in a database, and provides a web interface for search, upload, and comprehensive reporting.

## What It Does

This system handles the complete lifecycle of mortgage loan documents:
- **Automated Document Collection**: Scheduled cron jobs query external APIs and download loan documents (PDFs) automatically
- **Intelligent Queue Management**: Processes thousands of documents reliably with retry logic and error handling
- **Web-Based Interface**: Users can search documents, upload new files, and generate detailed reports
- **Data Integrity**: Validates file types, detects duplicates, and tracks document versions
- **Comprehensive Reporting**: Generates analytics on loan completion status, document statistics, and error logs

## Tech Stack

### **PHP 8+**
- **Backend Logic**: Object-oriented architecture with modular classes for API communication, database operations, and file processing
- **Web Interface**: Server-side rendering for search, upload, and reporting pages
- **Why PHP**: Fast development, excellent MySQL integration, and robust file handling for PDF processing

### **MySQL**
- **Document Storage**: Binary storage of PDF files with metadata (loan numbers, document types, timestamps)
- **Queue Management**: Tracks download status, retries, and processing states
- **Analytics**: Complex queries for reporting (aggregations, joins, JSON operations)
- **Why MySQL**: Reliable ACID transactions, efficient binary storage, and powerful querying for reporting

### **Bootstrap + jQuery**
- **Responsive UI**: Clean, professional interface for document search and management
- **User Experience**: Interactive forms, tables, and navigation
- **Why Bootstrap**: Rapid UI development with consistent styling and mobile responsiveness

### **cURL**
- **API Integration**: Handles HTTP requests with retry logic, timeout management, and error recovery
- **Session Management**: Maintains API sessions with automatic reconnection
- **Why cURL**: Reliable HTTP client with fine-grained control over connections and error handling

### **Cron Jobs**
- **Automation**: Scheduled tasks for session management, file discovery, and batch processing
- **Reliability**: Ensures continuous operation without manual intervention
- **Why Cron**: Industry-standard scheduling that works seamlessly with Linux servers

## Architecture

### **Layered Architecture**

```
┌─────────────────────────────────────┐
│      Web Interface (PHP)           │
│  - Search, Upload, Reports          │
└──────────────┬──────────────────────┘
               │
┌──────────────▼──────────────────────┐
│      Business Logic Layer            │
│  - ApiClient (API communication)     │
│  - DatabaseManager (data operations)  │
│  - QueueManager (job processing)    │
│  - SessionManager (API sessions)     │
│  - FileProcessor (validation)      │
└──────────────┬──────────────────────┘
               │
┌──────────────▼──────────────────────┐
│      Data Layer                      │
│  - MySQL Database                   │
│  - Document Storage (BLOBs)          │
│  - Queue Tables                     │
│  - API Call Logs                    │
└──────────────────────────────────────┘
```

### **Key Components**

1. **API Client Layer**: Handles all external API communication with retry logic, session management, and comprehensive error handling
2. **Queue System**: Processes documents asynchronously with status tracking, retry mechanisms, and batch processing
3. **Database Manager**: Provides secure, prepared-statement-based database operations with connection pooling
4. **Session Manager**: Maintains API sessions, handles expiration, and manages cleanup
5. **Web Interface**: Three main modules:
   - **Search**: Query documents by loan number, date, or document type
   - **Upload**: Manual document upload with validation
   - **Reports**: Analytics on loan completion, document statistics, and error tracking

### **Processing Flow**

1. **Discovery**: Cron job queries API for available files every hour
2. **Queueing**: New files are added to a download queue
3. **Processing**: Background jobs download files in batches (every 5 minutes)
4. **Validation**: Files are validated (MIME type, size, duplicates)
5. **Storage**: Valid documents are stored with metadata in MySQL
6. **Reporting**: Web interface provides real-time analytics and search

### **Reliability Features**

- **Retry Logic**: Automatic retries with exponential backoff for failed API calls
- **Connection Management**: Auto-reconnection for database and API sessions
- **Resource Monitoring**: Pauses processing when system resources are high
- **Error Logging**: Comprehensive audit trail of all API calls and errors
- **Duplicate Detection**: Prevents storing duplicate documents
- **Transaction Safety**: Database transactions ensure data consistency

## Project Highlights

- **Scalable**: Processes thousands of documents with batch processing and queue management
- **Reliable**: Comprehensive error handling, retry logic, and connection management
- **Observable**: Full audit trail with API call logging and error tracking
- **Production-Ready**: Includes cleanup scripts, data migration tools, and maintenance utilities

