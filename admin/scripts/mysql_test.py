import openai
import mysql.connector
from mysql.connector import Error

# 1. LLM prompt
prompt = """
You are a helpful assistant. Given the user query, produce a valid MySQL query
that fetches the required data. Return only the SQL statement.

User query: {user_query}
"""

# 2. OpenAI call
def generate_sql(user_query, api_key):
    response = openai.ChatCompletion.create(
        model="gpt-4o-mini",
        messages=[{"role": "system", "content": "You are a SQL generator."},
                  {"role": "user", "content": prompt.format(user_query=user_query)}],
        temperature=0,
    )
    return response['choices'][0]['message']['content'].strip()

# 3. Execute the query
def run_sql(sql, host, db, user, password):
    try:
        conn = mysql.connector.connect(host=host, database=db, user=user, password=password)
        cursor = conn.cursor()
        cursor.execute(sql)
        rows = cursor.fetchall()
        return rows
    except Error as e:
        return str(e)
    finally:
        if conn.is_connected():
            cursor.close()
            conn.close()

# Example usage
api_key = "YOUR_OPENAI_API_KEY"
user_query = "Show me the total sales per region for the last quarter."

sql = generate_sql(user_query, api_key)
print("Generated SQL:\n", sql)

# Replace with your own MySQL credentials
rows = run_sql(sql, host="localhost", db="sales_db", user="root", password="pass")
print(rows)
