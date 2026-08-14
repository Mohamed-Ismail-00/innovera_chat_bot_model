"""
Chat API Route
Handles chat requests with session management, rate limiting, and SSE streaming.
Includes automatic memory cleanup for rate limiter to prevent memory leaks.
"""

import time
import uuid
import logging
from collections import defaultdict
from fastapi import APIRouter, HTTPException
from fastapi.responses import StreamingResponse
from pydantic import BaseModel, Field
from app.services.rag_service import RAGService
from app.config import settings

logger = logging.getLogger(__name__)
router = APIRouter()

# ─── In-memory Rate Limiter with automatic cleanup ───
_rate_limit_store: dict[str, list[float]] = defaultdict(list)
_RATE_LIMIT_CLEANUP_INTERVAL = 300  # Clean up stale sessions every 5 minutes
_last_cleanup_time = time.time()


def _cleanup_stale_sessions():
    """Remove sessions with no activity in the last 5 minutes to prevent memory growth."""
    global _last_cleanup_time
    now = time.time()
    if now - _last_cleanup_time < _RATE_LIMIT_CLEANUP_INTERVAL:
        return

    _last_cleanup_time = now
    stale_keys = [
        k for k, v in _rate_limit_store.items()
        if not v or (now - max(v)) > _RATE_LIMIT_CLEANUP_INTERVAL
    ]
    for k in stale_keys:
        del _rate_limit_store[k]

    if stale_keys:
        logger.info(f"Rate limiter cleanup: removed {len(stale_keys)} stale sessions.")


def _check_rate_limit(session_id: str) -> bool:
    """Check if a session has exceeded the rate limit."""
    _cleanup_stale_sessions()

    now = time.time()
    window = 60.0  # 1 minute window

    _rate_limit_store[session_id] = [
        t for t in _rate_limit_store[session_id] if now - t < window
    ]

    if len(_rate_limit_store[session_id]) >= settings.rate_limit_per_minute:
        return False

    _rate_limit_store[session_id].append(now)
    return True


class ChatRequest(BaseModel):
    message: str = Field(..., min_length=1, max_length=500)
    session_id: str = Field(default_factory=lambda: str(uuid.uuid4()))


@router.post("/chat")
async def chat_endpoint(request: ChatRequest):
    """
    Endpoint for the chat interface.
    Streams back AI response using Server-Sent Events (SSE).
    """
    if len(request.message.strip()) == 0:
        raise HTTPException(status_code=400, detail="Message cannot be empty")

    if len(request.message) > settings.max_message_length:
        raise HTTPException(
            status_code=400,
            detail=f"Message too long. Maximum {settings.max_message_length} characters."
        )

    if not _check_rate_limit(request.session_id):
        raise HTTPException(
            status_code=429,
            detail="عذراً، أنت ترسل رسائل كثيرة. انتظر قليلاً وحاول مرة أخرى."
        )

    logger.info(f"Chat request from session {request.session_id[:8]}...")

    async def sse_generator():
        try:
            has_content = False
            async for chunk in RAGService.chat_stream(
                request.message,
                session_id=request.session_id,
            ):
                if chunk:  # Guard against empty chunks
                    has_content = True
                    # Escape newlines in chunk so multi-line text does not break SSE protocol frame parsing
                    safe_chunk = chunk.replace("\r\n", "\\n").replace("\n", "\\n")
                    yield f"data: {safe_chunk}\n\n"

            # If model returned nothing, send a user-friendly fallback
            if not has_content:
                fallback = "عذراً، لم أتمكن من إنشاء رد الآن. حاول مرة أخرى. 🙏"
                yield f"data: {fallback}\n\n"

            # Send end signal
            yield "data: [DONE]\n\n"
        except Exception as e:
            logger.exception(f"Error in chat stream: {e}")
            safe_error = str(e).replace("\n", " ")
            yield f"data: [ERROR] {safe_error}\n\n"

    return StreamingResponse(
        sse_generator(),
        media_type="text/event-stream",
        headers={
            "Cache-Control": "no-cache",
            "Connection": "keep-alive",
            "X-Accel-Buffering": "no",  # Disable Nginx buffering if proxied
        },
    )
