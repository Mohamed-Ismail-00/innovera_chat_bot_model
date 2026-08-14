"""
Vector Database Service
Handles storing and retrieving documents using ChromaDB.
"""

import chromadb
from chromadb.utils import embedding_functions
from app.config import settings
import logging

logger = logging.getLogger(__name__)

class VectorService:
    def __init__(self):
        # Initialize the ChromaDB client pointing to our persist directory
        self.client = chromadb.PersistentClient(path=settings.chroma_persist_dir)
        
        # Initialize the embedding function
        # This will download the model on first run if not present
        self.embedding_fn = embedding_functions.SentenceTransformerEmbeddingFunction(
            model_name=settings.embedding_model
        )
        
        self.collection_name = "innovera_knowledge"
        
        # Get or create the collection
        self.collection = self.client.get_or_create_collection(
            name=self.collection_name,
            embedding_function=self.embedding_fn
        )
        logger.info(f"VectorService initialized. Collection '{self.collection_name}' has {self.collection.count()} items.")

    def add_documents(self, documents: list[str], metadatas: list[dict], ids: list[str]):
        """Add documents to the collection."""
        try:
            self.collection.add(
                documents=documents,
                metadatas=metadatas,
                ids=ids
            )
            logger.info(f"Added {len(documents)} documents to the vector DB.")
        except Exception as e:
            logger.error(f"Error adding documents: {e}")
            raise

    def search(self, query: str, n_results: int = 5) -> list[dict]:
        """Search the collection for the most relevant documents."""
        try:
            results = self.collection.query(
                query_texts=[query],
                n_results=n_results
            )
            
            # Format the results
            formatted_results = []
            
            # ChromaDB returns lists of lists because you can pass multiple query_texts
            if results['documents'] and len(results['documents']) > 0:
                docs = results['documents'][0]
                metas = results['metadatas'][0] if results['metadatas'] else []
                distances = results['distances'][0] if results['distances'] else []
                
                for i in range(len(docs)):
                    # Optional: filter by distance if needed (lower is better in Chroma by default)
                    formatted_results.append({
                        "content": docs[i],
                        "metadata": metas[i] if i < len(metas) else {},
                        "score": distances[i] if i < len(distances) else None
                    })
                    
            return formatted_results
        except Exception as e:
            logger.error(f"Error searching vector DB: {e}")
            return []
