# 🚀 FoxKit Cloudflare Pages Deployment Guide

## What We've Created:

✅ **Cloudflare Function**: `functions/download.js` - Replaces PHP with serverless function  
✅ **Updated HTML**: `download.html` - Now calls Cloudflare Functions instead of PHP  
✅ **Configuration**: `wrangler.toml` and `_headers` for security  
✅ **GitHub Repository**: All files committed and pushed  

## 🔧 Cloudflare Pages Setup Steps:

### 1. Connect Repository to Cloudflare Pages
1. Go to [Cloudflare Dashboard](https://dash.cloudflare.com/)
2. Navigate to **Pages** → **Create a project**  
3. Connect to **GitHub** and select `foxkit-website` repository
4. **Build settings**:
   - Framework preset: `None`
   - Build command: (leave empty)
   - Build output directory: `/`
   - Root directory: `/`

### 2. Create KV Namespace (Required)
1. Go to **Workers & Pages** → **KV**
2. Create namespace: `foxkit-download-stats`
3. Copy the namespace ID
4. Update `wrangler.toml` with your namespace ID

### 3. Environment Variables (Optional)
Set in Cloudflare Pages settings:
- `ADMIN_IP`: Your IP address for admin access
- `MAX_DOWNLOADS`: `101` (or leave default)

### 4. Deploy
1. Cloudflare Pages will auto-deploy from your GitHub repo
2. Your site will be available at: `https://foxkit-website.pages.dev`
3. Custom domain: Configure in Cloudflare Pages settings

## 🎯 Live URLs After Deployment:

- **Main Site**: `https://foxkit-website.pages.dev/`
- **Download Page**: `https://foxkit-website.pages.dev/download.html`  
- **API Endpoints**:
  - Check: `https://foxkit-website.pages.dev/download?action=check`
  - Stats: `https://foxkit-website.pages.dev/download?action=stats`
  - Download: `https://foxkit-website.pages.dev/download?action=download`

## 🔒 Security Features:

✅ **101 Download Limit**: Enforced via Cloudflare KV storage  
✅ **Rate Limiting**: 3 requests per IP per 5 minutes  
✅ **Bot Protection**: User agent validation  
✅ **Real IP Detection**: Uses CF-Connecting-IP header  
✅ **Security Headers**: XSS, CSRF protection via `_headers`  
✅ **Serverless**: No server to hack, ultra-secure  

## 🚀 Advantages of Cloudflare Pages:

- **Free hosting** with generous limits
- **Global CDN** with instant deployment  
- **Automatic HTTPS** with SSL certificates
- **Git integration** - auto-deploy on push
- **Serverless functions** for backend logic
- **DDoS protection** built-in
- **Custom domains** supported

## 📱 QR Code URL:
Once deployed, update QR codes to: **https://foxkit-website.pages.dev/download.html**

Your ultra-secure 101-download limited beta program will be live on Cloudflare Pages! 🎪