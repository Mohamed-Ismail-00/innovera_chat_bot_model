"""
Ollama Service
Handles communication with the local Ollama instance for the Chatbot.
"""

import json
import logging
import httpx
from typing import AsyncGenerator
from app.config import settings

logger = logging.getLogger(__name__)

class OllamaService:
    def __init__(self):
        self.base_url = settings.ollama_base_url
        self.model = settings.ollama_model

    async def generate_response_stream(
        self, prompt: str, system_message: str
    ) -> AsyncGenerator[str, None]:
        """
        Sends a prompt and system message to Ollama and yields the response incrementally.
        """
        payload = {
            "model": self.model,
            "messages": [
                {"role": "system", "content": system_message},
                {"role": "user", "content": prompt}
            ],
            "stream": True
        }

        url = f"{self.base_url}/api/chat"

        try:
            # We use a relatively long timeout since local generation might take time
            async with httpx.AsyncClient(timeout=120.0) as client:
                async with client.stream("POST", url, json=payload) as response:
                    response.raise_for_status()
                    async for chunk in response.aiter_lines():
                        if not chunk:
                            continue
                        try:
                            data = json.loads(chunk)
                            if "message" in data and "content" in data["message"]:
                                yield data["message"]["content"]
                        except json.JSONDecodeError:
                            logger.error(f"Failed to decode Ollama response chunk: {chunk}")
                            continue
        except httpx.ConnectError:
            logger.error("Failed to connect to Ollama. Is it running?")
            yield "عذراً، أواجه مشكلة في الاتصال بالخادم المحلي للذكاء الاصطناعي (Ollama). يرجى التأكد من تشغيله."
        except Exception as e:
            logger.exception("Error during Ollama generation")
            yield f"عذراً، حدث خطأ غير متوقع: {str(e)}"
