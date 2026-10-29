FROM python:3.15-slim

WORKDIR /app

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY bot_stub.py .

EXPOSE 8001

CMD ["python", "bot_stub.py"]