/**
 * Cloudflare Pages Function for FoxKit Secure Download System
 * Replaces PHP with Cloudflare Workers/Functions
 */

const MAX_DOWNLOADS = 101;
const RATE_LIMIT_WINDOW = 300; // 5 minutes
const MAX_REQUESTS_PER_IP = 3;

export async function onRequest(context) {
    const { request, env } = context;
    const url = new URL(request.url);
    const action = url.searchParams.get('action') || 'download';
    
    // CORS headers
    const corsHeaders = {
        'Access-Control-Allow-Origin': '*',
        'Access-Control-Allow-Methods': 'GET, POST, OPTIONS',
        'Access-Control-Allow-Headers': 'Content-Type',
    };
    
    // Security headers
    const securityHeaders = {
        'X-Content-Type-Options': 'nosniff',
        'X-Frame-Options': 'DENY',
        'X-XSS-Protection': '1; mode=block',
        'Strict-Transport-Security': 'max-age=31536000; includeSubDomains',
        ...corsHeaders
    };
    
    if (request.method === 'OPTIONS') {
        return new Response(null, { headers: corsHeaders });
    }
    
    const clientIP = request.headers.get('CF-Connecting-IP') || 
                     request.headers.get('X-Forwarded-For') || 
                     '127.0.0.1';
    
    const userAgent = request.headers.get('User-Agent') || '';
    
    try {
        switch (action) {
            case 'check':
                return handleCheck(env, securityHeaders);
            case 'stats':
                return handleStats(env, clientIP, securityHeaders);
            case 'download':
                return await handleDownload(env, clientIP, userAgent, securityHeaders, context);
            default:
                return new Response(JSON.stringify({ error: 'Invalid action' }), {
                    status: 400,
                    headers: { ...securityHeaders, 'Content-Type': 'application/json' }
                });
        }
    } catch (error) {
        console.error('Function error:', error);
        return new Response(JSON.stringify({ error: 'Internal server error' }), {
            status: 500,
            headers: { ...securityHeaders, 'Content-Type': 'application/json' }
        });
    }
}

async function getDownloadStats(env) {
    try {
        const stats = await env.DOWNLOAD_STATS.get('foxkit_stats');
        if (stats) {
            return JSON.parse(stats);
        }
    } catch (error) {
        console.error('Error reading stats:', error);
    }
    
    // Default stats
    return {
        total_downloads: 0,
        remaining_downloads: MAX_DOWNLOADS,
        download_log: [],
        rate_limit: [],
        started: new Date().toISOString(),
        blocked_attempts: 0
    };
}

async function saveDownloadStats(env, stats) {
    try {
        await env.DOWNLOAD_STATS.put('foxkit_stats', JSON.stringify(stats));
    } catch (error) {
        console.error('Error saving stats:', error);
    }
}

async function checkRateLimit(env, clientIP) {
    const stats = await getDownloadStats(env);
    const currentTime = Math.floor(Date.now() / 1000);
    const windowStart = currentTime - RATE_LIMIT_WINDOW;
    
    // Clean old entries
    stats.rate_limit = stats.rate_limit.filter(entry => entry.timestamp > windowStart);
    
    // Count requests from this IP
    const ipRequests = stats.rate_limit.filter(entry => entry.ip === clientIP);
    
    if (ipRequests.length >= MAX_REQUESTS_PER_IP) {
        stats.blocked_attempts++;
        await saveDownloadStats(env, stats);
        return false;
    }
    
    // Add current request
    stats.rate_limit.push({
        ip: clientIP,
        timestamp: currentTime,
        user_agent: 'tracked'
    });
    
    await saveDownloadStats(env, stats);
    return true;
}

async function handleCheck(env, headers) {
    const stats = await getDownloadStats(env);
    return new Response(JSON.stringify({
        downloads_available: stats.remaining_downloads > 0,
        remaining: stats.remaining_downloads
    }), {
        headers: { ...headers, 'Content-Type': 'application/json' }
    });
}

async function handleStats(env, clientIP, headers) {
    // Simple admin IP check - replace with your IP
    const adminIPs = ['YOUR_ADMIN_IP_HERE', '127.0.0.1'];
    
    if (!adminIPs.includes(clientIP)) {
        return new Response(JSON.stringify({ error: 'Forbidden' }), {
            status: 403,
            headers: { ...headers, 'Content-Type': 'application/json' }
        });
    }
    
    const stats = await getDownloadStats(env);
    return new Response(JSON.stringify({
        total_downloads: stats.total_downloads,
        remaining_downloads: stats.remaining_downloads,
        percentage_used: Math.round((stats.total_downloads / MAX_DOWNLOADS) * 100 * 10) / 10,
        started: stats.started,
        blocked_attempts: stats.blocked_attempts
    }), {
        headers: { ...headers, 'Content-Type': 'application/json' }
    });
}

async function handleDownload(env, clientIP, userAgent, headers, context) {
    const stats = await getDownloadStats(env);
    
    // Check if downloads are exhausted
    if (stats.total_downloads >= MAX_DOWNLOADS) {
        stats.blocked_attempts++;
        await saveDownloadStats(env, stats);
        return new Response(JSON.stringify({
            success: false,
            error: 'Downloads exhausted',
            remaining: 0
        }), {
            status: 429,
            headers: { ...headers, 'Content-Type': 'application/json' }
        });
    }
    
    // Rate limiting
    if (!(await checkRateLimit(env, clientIP))) {
        return new Response(JSON.stringify({
            success: false,
            error: 'Rate limit exceeded',
            remaining: stats.remaining_downloads
        }), {
            status: 429,
            headers: { ...headers, 'Content-Type': 'application/json' }
        });
    }
    
    // Basic bot detection
    if (!userAgent || userAgent.length < 20) {
        stats.blocked_attempts++;
        await saveDownloadStats(env, stats);
        return new Response(JSON.stringify({
            success: false,
            error: 'Invalid request',
            remaining: stats.remaining_downloads
        }), {
            status: 403,
            headers: { ...headers, 'Content-Type': 'application/json' }
        });
    }
    
    // Serve the APK file from Cloudflare R2 or redirect to GitHub
    try {
        // Option 1: Redirect to GitHub raw file
        const apkUrl = 'https://github.com/cardoza1991/foxkit-website/raw/main/FoxKit-v1.1.0.apk';
        
        // Log successful download
        stats.total_downloads++;
        stats.remaining_downloads = MAX_DOWNLOADS - stats.total_downloads;
        stats.download_log.push({
            timestamp: new Date().toISOString(),
            ip: clientIP,
            user_agent: userAgent.substring(0, 200),
            download_id: stats.total_downloads
        });
        
        await saveDownloadStats(env, stats);
        
        // Redirect to APK file
        return Response.redirect(apkUrl, 302);
        
    } catch (error) {
        console.error('Download error:', error);
        return new Response(JSON.stringify({
            success: false,
            error: 'Download failed'
        }), {
            status: 500,
            headers: { ...headers, 'Content-Type': 'application/json' }
        });
    }
}